# ADR-008: Expo with EAS; encrypted SQLite with an outbox sync queue

**Status:** Accepted (approved 22 Nov 2026)

## Context
Learners and supervisors work offline on low-cost Android phones; one codebase for Android and iOS; fast releases.

## Decision
React Native with Expo and Expo Router; EAS Build/Submit/Update for TestFlight, Google Play and over-the-air fixes; SQLCipher-encrypted SQLite; an outbox queue with idempotency keys and retries; keys in Keychain/Keystore.

## Consequences
No Mac needed for iOS builds; urgent fixes ship without store review.
