import { describe, expect, it } from 'vitest';
import { problemsFor } from '../src/core/qcto';
import { routeFor } from '../src/core/notificationRoute';

describe('QCTO profile problems', () => {
  it('lists errors before warnings and drops repeats', () => {
    const p = problemsFor([
      { cohort: 'A', errors: [{ code: 'Q-POSTCODE', column: 'T', problem: 'Postal code must be 4 digits' }], warnings: [{ code: 'Q-AREA', column: 'U', problem: 'Stats SA area code is missing' }] },
      { cohort: 'A', errors: [{ code: 'Q-POSTCODE', column: 'T', problem: 'Postal code must be 4 digits' }], warnings: [] },
    ]);
    expect(p).toEqual(['A: Postal code must be 4 digits', 'A: Stats SA area code is missing']);
    expect(problemsFor(undefined)).toEqual([]);
  });
  it('routes an EISA push to results', () => {
    expect(routeFor({ type: 'eisa' })).toBe('/results');
  });
});
