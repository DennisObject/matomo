---
paths:
  - 'app/Matomo/Api/**'
---

# Api

## Preserve the Matomo API boundary
Keep module, method, query, response, and status contracts unchanged. Do not cut an endpoint over until auth, formats, permissions, side effects, and plugin hooks have parity and the legacy path remains a tested fallback.

## Keep every public response format exact
Scalar, row, and list responses must match Matomo for JSON, XML, CSV, TSV, HTML, original, console, and RSS output. Preserve content types, empty results, download headers, Unicode conversion, serialization, and JSONP validation.

## Keep migrated methods isolated
Put each plugin's migrated API methods in its own `ApiMethodHandler`. Register the handler in `ApiMethodDispatcher`; keep parsing, IP checks, and unsupported-method replies in the reporting API controller.

## Keep data access behind a contract
Put existing Matomo table reads in a small repository. Inject the repository contract into API handlers and use the shared `MatomoDatabase` connection so table prefixes and install settings stay central.

Quote commas, double quotes, and line breaks in single-column CSV and TSV lists exactly as Matomo does. Do not assume every list contains only numeric IDs.
