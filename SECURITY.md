# Security policy

## Reporting a vulnerability

If you believe you have found a security vulnerability in `padosoft/laravel-flow`, please email `lorenzo.padovani@padosoft.com` with:

- A description of the vulnerability and its impact
- Steps to reproduce
- Affected version(s)
- Any suggested mitigation, if known

Please do **not** open a public GitHub issue for security reports.

## Response timeline

- **Acknowledgement:** within 3 business days of receipt
- **Initial assessment:** within 7 business days
- **Patch + advisory:** as soon as feasible, typically within 30 days for high-severity issues

## Scope

This policy covers code published under the `padosoft/laravel-flow` Composer package on Packagist and the corresponding GitHub repository.

The following are out of scope:

- Vulnerabilities in user-supplied step handlers or compensators (host application responsibility)
- Vulnerabilities in the consuming Laravel application's configuration (e.g. weak credentials, misconfigured permissions)
- Theoretical attacks that require an attacker to already have control of the application's `.env` file or the `composer.json` registry

## Coordinated disclosure

The project supports coordinated disclosure. We will work with you on a public-disclosure timeline that gives users time to upgrade. Credit will be acknowledged in the release notes if you wish.
