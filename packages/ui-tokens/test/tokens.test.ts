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
