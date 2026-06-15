# Security Policy

## Supported Versions

Security fixes are provided for the latest tagged release.

## Reporting a Vulnerability

Please do not open a public issue for suspected vulnerabilities.

Email security reports to `oliwand@gmail.com` with:

- a short description of the issue,
- affected version or commit,
- reproduction steps,
- impact and suggested remediation, if known.

You can expect an initial response within 7 days.

## Data Handling Expectations

LaraMon is designed so monitored applications do not send request payloads or SQL bindings to the hub. If you find a path that can leak those values by default, treat it as a security issue.
