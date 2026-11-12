<?php

namespace App\Support\Domains;

use App\Models\CustomDomain;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Creates one Ingress per domain in this application's own namespace, routed
 * to the tunnel server. The Ingress names a cert-manager issuer, so the
 * cluster obtains and renews the certificate.
 *
 * It talks to the Kubernetes API with the pod's service account, which needs
 * get, create, patch and delete on ingresses in its namespace and nothing else.
 *
 * That right covers every Ingress in the namespace, including those of a second
 * install there, whose domain records are numbered from 1 as well. So names and
 * a label carry this install's identity, and an Ingress without that label is
 * never changed or deleted.
 */
class KubernetesIngressProvisioner implements Provisioner
{
    /**
     * @param  array{api_url: string, token_path: string, ca_path: string, namespace_path: string, ingress_class: string, cluster_issuer: string, service: string, service_port: int}  $config
     */
    public function __construct(private array $config)
    {
        //
    }

    public function provision(CustomDomain $domain): void
    {
        [$name, $existing] = $this->find($domain);

        if ($existing !== null && ! $this->owns($existing)) {
            throw new RuntimeException("The Ingress {$name} for {$domain->hostname} belongs to another installation; it was left as it is.");
        }

        $ingress = $this->ingress($domain, $name);

        if (isset($existing['metadata']['resourceVersion'])) {
            // The cluster rejects the apply if the object changed since it was read and checked.
            $ingress['metadata']['resourceVersion'] = $existing['metadata']['resourceVersion'];
        }

        // Server-side apply creates the Ingress or brings an existing one in line, in one request.
        // The API parses the body as YAML. JSON is YAML, except for the "\/" that PHP writes for
        // a slash by default, which YAML rejects as an unknown escape.
        $body = json_encode($ingress, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = $this->api()
            ->withBody($body, 'application/apply-patch+yaml')
            ->patch($this->url($name).'?fieldManager=exposehub-web&force=true');

        if ($response->failed()) {
            throw new RuntimeException("The cluster refused the Ingress for {$domain->hostname} (HTTP {$response->status()}).");
        }
    }

    public function remove(CustomDomain $domain): void
    {
        [$name, $existing] = $this->find($domain);

        // Gone already, or another installation's object of the same name: nothing of ours to delete.
        if ($existing === null || ! $this->owns($existing)) {
            return;
        }

        // The certificate is owned by the Ingress and goes with it. Its Secret stays behind:
        // deleting Secrets would need a permission that also reaches this application's own.
        // The preconditions make the cluster refuse if the object was replaced since it was checked.
        $response = $this->api()->delete($this->url($name), [
            'apiVersion' => 'v1',
            'kind' => 'DeleteOptions',
            'preconditions' => array_filter([
                'uid' => $existing['metadata']['uid'] ?? null,
                'resourceVersion' => $existing['metadata']['resourceVersion'] ?? null,
            ]),
        ]);

        if ($response->failed() && $response->status() !== 404) {
            throw new RuntimeException("The cluster refused to delete the Ingress for {$domain->hostname} (HTTP {$response->status()}).");
        }
    }

    public function issuesCertificates(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function ingress(CustomDomain $domain, string $name): array
    {
        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => [
                'name' => $name,
                'labels' => [
                    'app.kubernetes.io/managed-by' => 'exposehub-web',
                    'exposehub/custom-domain' => 'true',
                    'exposehub/instance' => $this->instance(),
                ],
                'annotations' => [
                    'cert-manager.io/cluster-issuer' => $this->config['cluster_issuer'],
                    // Tunnels carry uploads and long-lived connections; see the wildcard Ingress.
                    'nginx.ingress.kubernetes.io/proxy-body-size' => '0',
                    'nginx.ingress.kubernetes.io/proxy-read-timeout' => '3600',
                    'nginx.ingress.kubernetes.io/proxy-send-timeout' => '3600',
                ],
            ],
            'spec' => [
                'ingressClassName' => $this->config['ingress_class'],
                'tls' => [[
                    'hosts' => [$domain->hostname],
                    'secretName' => $name.'-tls',
                ]],
                'rules' => [[
                    'host' => $domain->hostname,
                    'http' => ['paths' => [[
                        'path' => '/',
                        'pathType' => 'Prefix',
                        'backend' => ['service' => [
                            'name' => $this->config['service'],
                            'port' => ['number' => $this->config['service_port']],
                        ]],
                    ]]],
                ]],
            ],
        ];
    }

    /**
     * The record id keeps the name short, valid and unique whatever the hostname is; the
     * prefix keeps it apart from another installation's in the same namespace.
     */
    private function name(CustomDomain $domain): string
    {
        return $this->instance().'-domain-'.$domain->id;
    }

    /**
     * This installation within the namespace. The chart names the tunnel server's Service
     * after the release ("<release>-server"), so its name already tells installations apart.
     */
    private function instance(): string
    {
        return (string) preg_replace('/-server$/', '', $this->config['service']);
    }

    /**
     * The domain's Ingress, as [name, object or null]. Ingresses created before names carried
     * the installation are called "exposehub-domain-<id>"; one of those is still this domain's
     * if this installation owns it.
     *
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function find(CustomDomain $domain): array
    {
        $name = $this->name($domain);

        if (($existing = $this->get($name)) !== null) {
            return [$name, $existing];
        }

        $legacy = 'exposehub-domain-'.$domain->id;

        if ($legacy !== $name && ($existing = $this->get($legacy)) !== null && $this->owns($existing)) {
            return [$legacy, $existing];
        }

        return [$name, null];
    }

    /**
     * Whether this installation created the Ingress. Older ones carry no installation label;
     * they are ours if they route to our own tunnel server, which another installation's never do.
     *
     * @param  array<string, mixed>  $ingress
     */
    private function owns(array $ingress): bool
    {
        $labels = $ingress['metadata']['labels'] ?? [];

        if (isset($labels['exposehub/instance'])) {
            return $labels['exposehub/instance'] === $this->instance();
        }

        return ($labels['app.kubernetes.io/managed-by'] ?? null) === 'exposehub-web'
            && ($ingress['spec']['rules'][0]['http']['paths'][0]['backend']['service']['name'] ?? null) === $this->config['service'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $name): ?array
    {
        $response = $this->api()->get($this->url($name));

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException("The cluster refused to read the Ingress {$name} (HTTP {$response->status()}).");
        }

        return (array) $response->json();
    }

    private function url(string $name): string
    {
        $namespace = trim((string) file_get_contents($this->config['namespace_path']));

        return rtrim($this->config['api_url'], '/')."/apis/networking.k8s.io/v1/namespaces/{$namespace}/ingresses/{$name}";
    }

    private function api(): PendingRequest
    {
        return Http::withToken(trim((string) file_get_contents($this->config['token_path'])))
            ->withOptions(['verify' => $this->config['ca_path']])
            ->acceptJson()
            ->timeout(10);
    }
}
