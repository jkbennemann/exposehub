{{- define "eh.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Prefix of every resource name. With a release named "exposehub" it is
"exposehub", so the resources are exposehub-server, exposehub-web and
exposehub-postgres.
*/}}
{{- define "eh.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := include "eh.name" . }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{- define "eh.labels" -}}
app.kubernetes.io/name: {{ include "eh.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
helm.sh/chart: {{ .Chart.Name }}-{{ .Chart.Version | replace "+" "_" }}
{{- end }}

{{/*
Selectors name the component only, not the release: selectors cannot change
after a Deployment or StatefulSet exists. Run one release per namespace, or
set nameOverride for a second one.
*/}}
{{- define "eh.serverSelector" -}}
app.kubernetes.io/name: {{ include "eh.name" . }}
app.kubernetes.io/component: server
{{- end }}

{{- define "eh.webSelector" -}}
app.kubernetes.io/name: {{ include "eh.name" . }}
app.kubernetes.io/component: web
{{- end }}

{{- define "eh.postgresSelector" -}}
app.kubernetes.io/name: {{ include "eh.name" . }}
app.kubernetes.io/component: postgres
{{- end }}

{{- define "eh.serverName" -}}{{ include "eh.fullname" . }}-server{{- end }}
{{- define "eh.webName" -}}{{ include "eh.fullname" . }}-web{{- end }}
{{- define "eh.postgresName" -}}{{ include "eh.fullname" . }}-postgres{{- end }}

{{- define "eh.serverImage" -}}
{{ .Values.server.image.repository }}:{{ .Values.server.image.tag | default .Chart.AppVersion }}
{{- end }}

{{- define "eh.webImage" -}}
{{ .Values.web.image.repository }}:{{ .Values.web.image.tag | default .Chart.AppVersion }}
{{- end }}

{{- define "eh.tunnelDomain" -}}
{{- required "server.tunnelDomain is required, e.g. tunnels.example.net" .Values.server.tunnelDomain }}
{{- end }}

{{/* Every tunnel domain, the default first. */}}
{{- define "eh.tunnelDomains" -}}
{{- prepend .Values.server.additionalTunnelDomains (include "eh.tunnelDomain" .) | join "," }}
{{- end }}

{{- define "eh.agentHost" -}}
{{- .Values.server.agentHost | default (printf "connect.%s" (include "eh.tunnelDomain" .)) }}
{{- end }}

{{- define "eh.webHost" -}}
{{- required "web.host is required, e.g. dash.example.com" .Values.web.host }}
{{- end }}

{{- define "eh.tunnelTlsSecret" -}}
{{- .Values.ingress.tlsSecretName | default (printf "%s-tunnels-tls" (include "eh.fullname" .)) }}
{{- end }}

{{- define "eh.webTlsSecret" -}}
{{- .Values.web.ingress.tls.secretName | default (printf "%s-web-tls" (include "eh.fullname" .)) }}
{{- end }}

{{- define "eh.issuerName" -}}
{{- .Values.ingress.certificate.issuer.name | default (printf "%s-letsencrypt" (include "eh.fullname" .)) }}
{{- end }}

{{/* The Secret the server reads its keys from. */}}
{{- define "eh.serverSecret" -}}
{{- .Values.secrets.existingSecret | default (include "eh.serverName" .) }}
{{- end }}

{{/* The Secret the dashboard and Postgres read their keys from. */}}
{{- define "eh.webSecret" -}}
{{- .Values.secrets.existingSecret | default (include "eh.webName" .) }}
{{- end }}

{{- define "eh.secretEnv" -}}
- name: {{ .name }}
  valueFrom:
    secretKeyRef:
      name: {{ .secret }}
      key: {{ .name }}
      {{- if .optional }}
      optional: true
      {{- end }}
{{- end }}

{{/* Egress to the cluster's DNS, Cilium flavour. */}}
{{- define "eh.ciliumDnsEgress" -}}
- toEndpoints:
    - matchLabels:
        k8s:io.kubernetes.pod.namespace: kube-system
        k8s-app: kube-dns
  toPorts:
    - ports:
        - port: "53"
          protocol: UDP
        - port: "53"
          protocol: TCP
{{- end }}

{{/* Egress to the cluster's DNS, standard NetworkPolicy flavour. */}}
{{- define "eh.dnsEgress" -}}
- to:
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: kube-system
      podSelector:
        matchLabels:
          k8s-app: kube-dns
  ports:
    - port: 53
      protocol: UDP
    - port: 53
      protocol: TCP
{{- end }}

{{- define "eh.ingressNamespace" -}}
{{- required "networkPolicy.ingressNamespace is required: the namespace of your ingress controller, so that only it reaches the server and the dashboard. Without network policies (networkPolicy.type: none), every pod in server.trustedProxies can claim any visitor address; choose none only on purpose and then trust only the ingress controller's own addresses." .Values.networkPolicy.ingressNamespace }}
{{- end }}
