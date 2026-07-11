# Changelog

## [0.2.0](https://github.com/getmilpa/resolver/compare/v0.1.0...v0.2.0) (2026-07-11)


### ⚠ BREAKING CHANGES

* acceptedRisks entries are objects {code, reason, expires?} (bare strings rejected with a teaching message); un-permitted legacy contracts now yield status blocked via MILPA_LEGACY_NOT_ALLOWED.

### Features

* report shape contracts, acceptedRisks with reason and expiry, allowedLegacyContracts enforcement ([6b5a612](https://github.com/getmilpa/resolver/commit/6b5a6122ca5636f8ef93b95ab6e1f47f778e3853))

## 0.1.0 (2026-07-11)


### Features

* milpa/resolver 0.1.0 — resolve the architecture before booting it ([a5f8471](https://github.com/getmilpa/resolver/commit/a5f847132aac856c9babe1095ef404cc0003fd32))
