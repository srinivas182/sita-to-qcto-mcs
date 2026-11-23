/** Choices for the details the QCTO learner enrolment file asks for (mirrors QctoCodes on the API). */
export const TITLES = ['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Prof'] as const;

export const NATIONALITIES: Array<[string, string]> = [
  ['SA', 'South Africa'], ['ZIM', 'Zimbabwe'], ['MOZ', 'Mozambique'], ['LES', 'Lesotho'], ['MAL', 'Malawi'], ['SWA', 'Eswatini'],
  ['BOT', 'Botswana'], ['NAM', 'Namibia'], ['ZAM', 'Zambia'], ['ANG', 'Angola'], ['TAN', 'Tanzania'], ['ZAI', 'DR Congo'],
  ['MAU', 'Mauritius'], ['SEY', 'Seychelles'], ['SDC', 'Other SADC country'], ['ROA', 'Rest of Africa'], ['EUR', 'Europe'],
  ['AIS', 'Asia'], ['NOR', 'North America'], ['SOU', 'South or Central America'], ['AUS', 'Australia and Oceania'], ['OOC', 'Other'],
];

export const CITIZEN: Array<[string, string]> = [['SA', 'South African citizen'], ['PR', 'Permanent resident'], ['D', 'Dual citizenship'], ['O', 'Other']];

export const WORK_STATUS: Array<[string, string]> = [
  ['01', 'Employed'], ['02', 'Unemployed, looking for work'], ['03', 'Not working, not looking'], ['04', 'Home-maker'],
  ['06', 'Scholar or student'], ['07', 'Pensioner or retired'], ['08', 'Not working: disabled'], ['09', 'Not working: no wish to work'],
  ['10', 'Not working: other reason'],
];

export const PROVINCES = ['Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape'] as const;

export interface QctoCheck {
  cohort: string;
  errors: { code: string; column: string; problem: string }[];
  warnings: { code: string; column: string; problem: string }[];
}

/** Problems the learner can fix themselves, one line each, errors first. */
export function problemsFor(checks: QctoCheck[] | undefined): string[] {
  const out: string[] = [];
  for (const c of checks ?? []) {
    for (const f of c.errors) out.push(`${c.cohort}: ${f.problem}`);
  }
  for (const c of checks ?? []) {
    for (const f of c.warnings) out.push(`${c.cohort}: ${f.problem}`);
  }
  return [...new Set(out)];
}
