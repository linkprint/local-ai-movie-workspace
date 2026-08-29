# Dependency lifecycle

The application is intentionally pinned to Laravel 12 by the v2.1 architecture baseline. The lock file records the exact framework and package versions used to build each release.

- Run `composer audit --locked` and `npm audit --omit=dev` every week and before each release.
- Accept Laravel 12 security and compatible patch releases only after the full Gate test suite passes.
- Complete Laravel 13 compatibility testing by 2027-01-15.
- Complete the production upgrade to Laravel 13 before Laravel 12 security support ends on 2027-02-24.
- Do not update a production lock file in place; build and test a new release, then deploy its immutable image.
