import { defineConfig } from 'vitest/config';

// Unit tests cover the platform-independent core (outbox, tokens, offline policy).
export default defineConfig({ test: { include: ['test/**/*.test.ts'], environment: 'node' } });
