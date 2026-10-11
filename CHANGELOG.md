# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the versioning
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html) — which for
a payment library means the seal calculation, the field names and the shape of
a verified notification are the public surface, not just the method signatures.

## [Unreleased]

### Added

- The seal of version 3.0 (`AlphabeticalSeal`) and the one orders created
  before Monetico's migration still arrive with (`LegacyPositionalSeal`), with
  `SealVerifier` trying both.
- Payment forms: hidden inputs, a whole `<form>`, or a URL for the iframe
  integration, with the HTML escaping applied after sealing.
- The `contexte_commande` document for 3D Secure 2 — billing, shipping,
  basket and customer — with every documented attribute checked by name.
- Split payments in two, three or four instalments.
- The COFIDIS customer pre-fill fields, hex-encoded as the partner requires.
- Notification verification over every field received, the `Acknowledgement`
  the platform requires, and typed readings of the result, the card, the
  refusal reasons, the fraud-module filters and the 3D Secure document.
- The capture service: capture, partial capture, cancellation and recurrence
  stop, with the amount arithmetic checked before the request is sent.
- The refund service, whole or partial, including the card-only shortcut that
  omits the authorisation number.
- Laravel service provider and Symfony bundle, each with the two request and
  response helpers that keep a query parameter out of a signature.
