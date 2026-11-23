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

import { rand, validAccount } from '../src/core/stipend';

describe('stipends', () => {
  it('shows rand and checks account numbers', () => {
    expect(rand(150000)).toBe('R1 500,00');
    expect(rand(90050)).toBe('R900,50');
    expect(validAccount('1234 5678 90')).toBe('1234567890');
    expect(validAccount('12a45')).toBeNull();
  });
  it('routes a stipend push to the stipend screen', () => {
    expect(routeFor({ type: 'stipend' })).toBe('/stipends');
  });
});
