# Security policy

## Report a vulnerability

Email **[scott.fillman@subschema.io](mailto:scott.fillman@subschema.io)** with the subject **Aggregate security report**.
Do not open a public issue or pull request containing vulnerability details.

Include the affected version or commit, installation method, relevant PHP/database
versions, the impact, and steps to reproduce with synthetic data. Explain whether
the issue requires authentication, a particular configuration, or consent state.
Configuration disclosure, unauthorized access to raw events, consent bypasses,
and release-signature or package-verification bypasses are security concerns.

Do not send production credentials, signing keys, database dumps, or real analytics
events. If sensitive evidence is necessary, first ask the maintainers to arrange
an appropriate private channel. Test only systems you own or have permission to
assess.

## Supported code and disclosure

Security fixes prioritize the current `master` branch and the latest stable
release, when available. Older release lines have no guaranteed backport policy;
include older affected versions in your report so maintainers can assess scope.

Maintainers will assess reproducibility and impact, coordinate fixes and disclosure
with the reporter, and publish an advisory when appropriate. Reporters may request
credit or anonymity. There is no fixed response-time commitment.

Use [GitHub issues](https://github.com/Subschema-LLC/aggregate/issues) for ordinary
bugs and configuration questions after removing secrets and personal data.
Community conduct concerns follow [the Code of Conduct](CODE_OF_CONDUCT.md).

## Deployment guidance

The [privacy and compliance guide](docs/PRIVACY-COMPLIANCE.md) describes collection
limits and operator responsibilities. Follow the [deployment guide](DEPLOYMENT.md)
for configuration, access controls, and updates, and verify prepared ZIPs as
described in the [release guide](docs/RELEASES.md).
