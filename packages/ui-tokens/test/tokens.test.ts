import { describe, expect, it } from 'vitest';
import { colors, contrastRatio } from '../src/index';

describe('design tokens meet WCAG 2.2 AA', () => {
  it('body text on canvas and surface', () => {
    expect(contrastRatio(colors.text, colors.canvas)).toBeGreaterThanOrEqual(4.5);
    expect(contrastRatio(colors.textMuted, colors.surface)).toBeGreaterThanOrEqual(4.5);
  });
  it('white text on primary buttons', () => {
    expect(contrastRatio('#ffffff', colors.primary[700])).toBeGreaterThanOrEqual(4.5);
  });
  it('status text on its own background', () => {
    for (const s of [colors.success, colors.warning, colors.danger, colors.info]) {
      expect(contrastRatio(s.fg, s.bg)).toBeGreaterThanOrEqual(4.5);
    }
  });
});

describe('NativeWind preset', () => {
  it('mirrors the shared colour tokens', async () => {
    const { createRequire } = await import('node:module');
    const preset = createRequire(import.meta.url)('../src/nativewind-preset.cjs') as {
      theme: { extend: { colors: Record<string, unknown> } };
    };
    const c = preset.theme.extend.colors;

    expect(c.primary).toEqual(colors.primary);
    expect([c.canvas, c.surface, c.line, c.ink, c.muted]).toEqual([colors.canvas, colors.surface, colors.line, colors.text, colors.textMuted]);
    expect([c.success, c.warning, c.danger, c.info]).toEqual([colors.success.fg, colors.warning.fg, colors.danger.fg, colors.info.fg]);
  });
});

describe('secondary text', () => {
  it('faint text is still readable (AA for small text) on every background', () => {
    for (const bg of [colors.surface, colors.canvas, colors.surfaceMuted]) {
      expect(contrastRatio(colors.textFaint, bg)).toBeGreaterThanOrEqual(4.5);
    }
  });
});
