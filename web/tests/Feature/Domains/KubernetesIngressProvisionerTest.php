<?php

namespace Tests\Feature\Domains;

use App\Models\CustomDomain;
use App\Models\User;
use App\Support\Domains\KubernetesIngressProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class KubernetesIngressProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/exposehub-k8s-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->dir);
        File::put($this->dir.'/token', "service-account-token\n");
        File::put($this->dir.'/namespace', 'tunnels');
        File::put($this->dir.'/ca.crt', '');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function provisioner(string $service = 'exposehub-server'): KubernetesIngressProvisioner
    {
        return new KubernetesIngressProvisioner([
            'api_url' => 'https://k8s.test',
            'token_path' => $this->dir.'/token',
            'ca_path' => $this->dir.'/ca.crt',
            'namespace_path' => $this->dir.'/namespace',
            'ingress_class' => 'nginx',
            'cluster_issuer' => 'letsencrypt-prod',
            'service' => $service,
            'service_port' => 80,
        ]);
    }

    /**
     * A cluster that holds the given Ingresses by name and accepts every write.
     *
     * @param  array<string, array<string, mixed>>  $ingresses
     */
    private function cluster(array $ingresses = []): void
    {
        Http::fake(function (Request $request) use ($ingresses) {
            if ($request->method() !== 'GET') {
                return Http::response([], 200);
            }

            $name = basename(parse_url($request->url(), PHP_URL_PATH));

            return isset($ingresses[$name]) ? Http::response($ingresses[$name], 200) : Http::response([], 404);
        });
    }

    /**
     * An Ingress as the cluster returns it.
     *
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private function existing(array $labels, string $service, string $uid = 'uid-1', string $version = '42'): array
    {
        return [
            'metadata' => ['labels' => $labels, 'uid' => $uid, 'resourceVersion' => $version],
            'spec' => ['rules' => [['http' => ['paths' => [['backend' => ['service' => ['name' => $service]]]]]]]],
        ];
    }

    /**
     * @return list<Request>
     */
    private function writes(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])->filter(fn (Request $request) => $request->method() !== 'GET')->values()->all();
    }

    private function domain(): CustomDomain
    {
        $user = User::factory()->create();

        return CustomDomain::factory()->forMember($user, $user->currentTeam)->connected()->create(['hostname' => 'dev.example.org']);
    }

    public function test_it_applies_one_ingress_for_the_domain_routed_to_the_tunnel_server(): void
    {
        $this->cluster();
        $domain = $this->domain();

        $this->provisioner()->provision($domain);

        Http::assertSent(function (Request $request) use ($domain) {
            $ingress = json_decode($request->body(), true);
            $name = 'exposehub-domain-'.$domain->id;

            // The cluster parses the body as YAML, which has no "\/" escape.
            $this->assertStringNotContainsString('\\/', $request->body());

            return $request->method() === 'PATCH'
                && $request->url() === "https://k8s.test/apis/networking.k8s.io/v1/namespaces/tunnels/ingresses/{$name}?fieldManager=exposehub-web&force=true"
                && $request->hasHeader('Authorization', 'Bearer service-account-token')
                && $request->hasHeader('Content-Type', 'application/apply-patch+yaml')
                && $ingress['metadata']['name'] === $name
                && $ingress['metadata']['labels']['exposehub/instance'] === 'exposehub'
                && $ingress['metadata']['annotations']['cert-manager.io/cluster-issuer'] === 'letsencrypt-prod'
                && $ingress['spec']['ingressClassName'] === 'nginx'
                && $ingress['spec']['tls'] === [['hosts' => ['dev.example.org'], 'secretName' => $name.'-tls']]
                && $ingress['spec']['rules'][0]['host'] === 'dev.example.org'
                && $ingress['spec']['rules'][0]['http']['paths'][0]['backend']['service'] === ['name' => 'exposehub-server', 'port' => ['number' => 80]];
        });
    }

    public function test_a_refusal_by_the_cluster_is_an_error(): void
    {
        Http::fake(fn (Request $request) => Http::response([], $request->method() === 'GET' ? 404 : 403));

        $this->expectException(RuntimeException::class);

        $this->provisioner()->provision($this->domain());
    }

    public function test_removing_deletes_the_ingress_and_tolerates_one_that_is_already_gone(): void
    {
        $domain = $this->domain();
        $name = 'exposehub-domain-'.$domain->id;
        Http::fake(function (Request $request) {
            return match ($request->method()) {
                'GET' => Http::response($this->existing(['exposehub/instance' => 'exposehub'], 'exposehub-server'), 200),
                default => Http::response([], 404),
            };
        });

        $this->provisioner()->remove($domain);

        $this->cluster();
        $this->provisioner()->remove($domain);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/namespaces/tunnels/ingresses/'.$name)
            && $request['preconditions'] === ['uid' => 'uid-1', 'resourceVersion' => '42']);
    }

    public function test_two_installations_in_one_namespace_get_their_own_ingress_names_and_labels(): void
    {
        $this->cluster();
        $domain = $this->domain();

        $this->provisioner('rel-a-server')->provision($domain);
        $this->provisioner('rel-b-server')->provision($domain);

        $applied = array_map(fn (Request $request) => json_decode($request->body(), true), $this->writes());

        $this->assertSame("rel-a-domain-{$domain->id}", $applied[0]['metadata']['name']);
        $this->assertSame("rel-b-domain-{$domain->id}", $applied[1]['metadata']['name']);
        $this->assertSame("rel-a-domain-{$domain->id}-tls", $applied[0]['spec']['tls'][0]['secretName']);
        $this->assertSame("rel-b-domain-{$domain->id}-tls", $applied[1]['spec']['tls'][0]['secretName']);
        $this->assertSame('rel-a', $applied[0]['metadata']['labels']['exposehub/instance']);
        $this->assertSame('rel-b', $applied[1]['metadata']['labels']['exposehub/instance']);
    }

    public function test_it_refuses_to_apply_over_another_installations_ingress(): void
    {
        $domain = $this->domain();
        $this->cluster(["rel-b-domain-{$domain->id}" => $this->existing(['exposehub/instance' => 'rel-a'], 'rel-a-server')]);

        try {
            $this->provisioner('rel-b-server')->provision($domain);
            $this->fail('The Ingress of another installation was overwritten.');
        } catch (RuntimeException) {
            $this->assertSame([], $this->writes());
        }
    }

    public function test_removing_leaves_another_installations_ingress_alone(): void
    {
        $domain = $this->domain();
        // An Ingress from before names carried the installation, created by the release "exposehub".
        $this->cluster(["exposehub-domain-{$domain->id}" => $this->existing(['app.kubernetes.io/managed-by' => 'exposehub-web'], 'exposehub-server')]);

        $this->provisioner('rel-b-server')->remove($domain);

        $this->assertSame([], $this->writes());
    }

    public function test_an_ingress_created_before_names_carried_the_installation_is_still_found(): void
    {
        $domain = $this->domain();
        $legacy = "exposehub-domain-{$domain->id}";
        $this->cluster([$legacy => $this->existing(['app.kubernetes.io/managed-by' => 'exposehub-web'], 'rel-b-server')]);

        $this->provisioner('rel-b-server')->provision($domain);
        $this->provisioner('rel-b-server')->remove($domain);

        [$apply, $delete] = $this->writes();
        $ingress = json_decode($apply->body(), true);

        $this->assertStringContainsString("/ingresses/{$legacy}?", $apply->url());
        $this->assertSame($legacy, $ingress['metadata']['name']);
        $this->assertSame($legacy.'-tls', $ingress['spec']['tls'][0]['secretName']);
        $this->assertSame('42', $ingress['metadata']['resourceVersion']);
        $this->assertSame('DELETE', $delete->method());
        $this->assertStringEndsWith("/ingresses/{$legacy}", $delete->url());
    }
}
