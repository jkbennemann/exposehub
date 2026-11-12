<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tunnel server
    |--------------------------------------------------------------------------
    |
    | "agent_server" is the address agents connect to and "tunnel_domain" the
    | domain tunnels get subdomains of; both are only shown to people, in the
    | commands and examples the dashboard prints. "node_secret" is the
    | shared secret the tunnel server presents on the internal API. While it
    | is empty the internal API refuses every request.
    |
    */

    'agent_server' => env('EXPOSEHUB_AGENT_SERVER', 'https://connect.example.com'),

    'tunnel_domain' => env('EXPOSEHUB_TUNNEL_DOMAIN', 'example.com'),

    // Where teams point their own domains: the host a CNAME record should
    // name. Defaults to the agent server's host.
    'custom_domain_target' => env('EXPOSEHUB_CUSTOM_DOMAIN_TARGET')
        ?: parse_url((string) env('EXPOSEHUB_AGENT_SERVER', 'https://connect.example.com'), PHP_URL_HOST),

    // What a tunnel address looks like, for the examples in the dashboard. It
    // uses the agent server's scheme, so a plain-HTTP test install shows http.
    'example_address' => (parse_url((string) env('EXPOSEHUB_AGENT_SERVER', 'https://connect.example.com'), PHP_URL_SCHEME) ?: 'https')
        .'://your-name.'.env('EXPOSEHUB_TUNNEL_DOMAIN', 'example.com'),

    'node_secret' => env('EXPOSEHUB_NODE_SECRET'),

    // How many days request logs are kept before they are deleted.
    'log_retention_days' => (int) env('EXPOSEHUB_LOG_RETENTION_DAYS', 7),

    // Where people can turn when their account is gone or out of their
    // control. Named in the mails about it; left out of them when empty.
    'contact_email' => env('EXPOSEHUB_CONTACT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Who may have an account
    |--------------------------------------------------------------------------
    |
    | Email domains accounts are limited to, for example "example.com,
    | example.org". It applies when someone registers and when someone
    | changes their address. Leave it empty to let anyone register.
    |
    */

    'allowed_email_domains' => array_filter(array_map('trim', explode(',', (string) env('EXPOSEHUB_ALLOWED_EMAIL_DOMAINS', '')))),

    // Allowed domains that are never named on a page or in an error message,
    // for example a private domain. They still work for registration.
    'hidden_email_domains' => array_filter(array_map('trim', explode(',', (string) env('EXPOSEHUB_HIDDEN_EMAIL_DOMAINS', '')))),

    /*
    |--------------------------------------------------------------------------
    | Platform owners
    |--------------------------------------------------------------------------
    |
    | Email addresses of the people who run this installation, comma
    | separated. Once such an address is verified, its account is an admin
    | that cannot be demoted, suspended or deleted from the admin area, so
    | the operator cannot be locked out. Owners can make other people admins.
    |
    */

    'admin_emails' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('EXPOSEHUB_ADMIN_EMAILS', '')),
    ))),

    // How many days entries in the admin audit log are kept.
    'admin_audit_retention_days' => (int) env('EXPOSEHUB_ADMIN_AUDIT_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Certificates for teams' own domains
    |--------------------------------------------------------------------------
    |
    | What has to happen in front of the tunnel server when a team connects a
    | domain. "none" does nothing, for proxies that issue certificates on
    | demand. "kubernetes" creates one Ingress per domain in this app's own
    | namespace and lets cert-manager obtain the certificate; it needs a
    | service account that may get, create, patch and delete ingresses there.
    |
    */

    'domain_provisioner' => [
        'driver' => env('EXPOSEHUB_DOMAIN_PROVISIONER', 'none'),

        'kubernetes' => [
            'api_url' => env('EXPOSEHUB_K8S_API_URL', 'https://kubernetes.default.svc'),
            'token_path' => '/var/run/secrets/kubernetes.io/serviceaccount/token',
            'ca_path' => '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt',
            'namespace_path' => '/var/run/secrets/kubernetes.io/serviceaccount/namespace',
            'ingress_class' => env('EXPOSEHUB_K8S_INGRESS_CLASS', 'nginx'),
            'cluster_issuer' => env('EXPOSEHUB_K8S_CLUSTER_ISSUER', 'letsencrypt-prod'),
            'service' => env('EXPOSEHUB_K8S_TUNNEL_SERVICE', 'exposehub-server'),
            'service_port' => (int) env('EXPOSEHUB_K8S_TUNNEL_SERVICE_PORT', 80),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reverse proxies
    |--------------------------------------------------------------------------
    |
    | Addresses or CIDRs of the proxies in front of this application, comma
    | separated, or "*" to trust the connecting address whatever it is. Their
    | X-Forwarded-* headers decide whether a request counts as HTTPS and what
    | the visitor's address is. Passkeys and secure cookies depend on it.
    |
    */

    'trusted_proxies' => env('EXPOSEHUB_TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Legal pages
    |--------------------------------------------------------------------------
    |
    | The imprint and the privacy notice are the operator's own texts, so they
    | are not part of this application. Put "imprint.md" and "privacy.md"
    | (Markdown) into this directory and the pages /imprint and /privacy,
    | and the links to them, appear. resources/legal holds starting points.
    |
    */

    'legal_path' => env('EXPOSEHUB_LEGAL_PATH', storage_path('app/private/legal')),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | How much one person or team may set up. Saved addresses keep names under
    | the shared tunnel domain from everyone else, and every team's networks
    | and domains go to the tunnel server in one shared snapshot, so no single
    | account may grow them without bound.
    |
    | A variable that is set but empty (as Compose passes an unset one) keeps
    | the default; 0 means none may be created.
    |
    */

    'limits' => [
        'teams_per_user' => is_numeric(env('EXPOSEHUB_MAX_TEAMS_PER_USER')) ? (int) env('EXPOSEHUB_MAX_TEAMS_PER_USER') : 10,
        'saved_addresses_per_user' => is_numeric(env('EXPOSEHUB_MAX_SAVED_ADDRESSES_PER_USER')) ? (int) env('EXPOSEHUB_MAX_SAVED_ADDRESSES_PER_USER') : 25,
        'networks_per_team' => is_numeric(env('EXPOSEHUB_MAX_NETWORKS_PER_TEAM')) ? (int) env('EXPOSEHUB_MAX_NETWORKS_PER_TEAM') : 25,
        'domains_per_team' => is_numeric(env('EXPOSEHUB_MAX_DOMAINS_PER_TEAM')) ? (int) env('EXPOSEHUB_MAX_DOMAINS_PER_TEAM') : 25,
        // Each sign-up mails a confirmation link to the address given.
        'registrations_per_ip_per_hour' => is_numeric(env('EXPOSEHUB_MAX_REGISTRATIONS_PER_IP_PER_HOUR')) ? (int) env('EXPOSEHUB_MAX_REGISTRATIONS_PER_IP_PER_HOUR') : 5,
        // Each reset request mails the address given.
        'password_reset_links_per_ip_per_hour' => is_numeric(env('EXPOSEHUB_MAX_PASSWORD_RESET_LINKS_PER_IP_PER_HOUR')) ? (int) env('EXPOSEHUB_MAX_PASSWORD_RESET_LINKS_PER_IP_PER_HOUR') : 10,
        'password_reset_links_per_address_per_hour' => is_numeric(env('EXPOSEHUB_MAX_PASSWORD_RESET_LINKS_PER_ADDRESS_PER_HOUR')) ? (int) env('EXPOSEHUB_MAX_PASSWORD_RESET_LINKS_PER_ADDRESS_PER_HOUR') : 3,
        // Wrong passwords from a signed-in session (confirmation, account deletion,
        // password change), counted per account like sign-in. This and the
        // two-factor limits are at least 1: at 0 nobody could confirm a password
        // or finish signing in.
        'password_checks_per_minute' => is_numeric(env('EXPOSEHUB_MAX_PASSWORD_CHECKS_PER_MINUTE')) ? max(1, (int) env('EXPOSEHUB_MAX_PASSWORD_CHECKS_PER_MINUTE')) : 5,
        // Two-factor codes per pending sign-in: per minute, per day, and wrong
        // ones in a row before the password has to be entered again.
        'two_factor_attempts_per_minute' => is_numeric(env('EXPOSEHUB_MAX_TWO_FACTOR_ATTEMPTS_PER_MINUTE')) ? max(1, (int) env('EXPOSEHUB_MAX_TWO_FACTOR_ATTEMPTS_PER_MINUTE')) : 5,
        'two_factor_attempts_per_day' => is_numeric(env('EXPOSEHUB_MAX_TWO_FACTOR_ATTEMPTS_PER_DAY')) ? max(1, (int) env('EXPOSEHUB_MAX_TWO_FACTOR_ATTEMPTS_PER_DAY')) : 20,
        'two_factor_failures_in_a_row' => is_numeric(env('EXPOSEHUB_MAX_TWO_FACTOR_FAILURES_IN_A_ROW')) ? max(1, (int) env('EXPOSEHUB_MAX_TWO_FACTOR_FAILURES_IN_A_ROW')) : 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Source code
    |--------------------------------------------------------------------------
    |
    | Every footer links "source_url" with the running "version", so people
    | can see what an installation runs. Point it at your fork when you run a
    | changed version, or set it empty to hide the link. Release images set
    | EXPOSEHUB_VERSION themselves.
    |
    */

    'source_url' => env('EXPOSEHUB_SOURCE_URL', 'https://github.com/jkbennemann/exposehub'),

    'version' => env('EXPOSEHUB_VERSION', 'dev'),

];
