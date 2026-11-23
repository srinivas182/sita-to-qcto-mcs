/** Choices for the details the QCTO learner enrolment file asks for. */
export const TITLES = ['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Prof'];

export const LANGUAGES = [
  'English',
  'Afrikaans',
  'isiNdebele',
  'isiXhosa',
  'isiZulu',
  'Sepedi',
  'Sesotho',
  'Setswana',
  'siSwati',
  'Tshivenda',
  'Xitsonga',
  'South African Sign Language',
  'Other',
];

export const NATIONALITIES: Record<string, string> = {
  SA: 'South Africa',
  ZIM: 'Zimbabwe',
  MOZ: 'Mozambique',
  LES: 'Lesotho',
  MAL: 'Malawi',
  SWA: 'Eswatini',
  BOT: 'Botswana',
  NAM: 'Namibia',
  ZAM: 'Zambia',
  ANG: 'Angola',
  TAN: 'Tanzania',
  ZAI: 'DR Congo',
  MAU: 'Mauritius',
  SEY: 'Seychelles',
  SDC: 'Other SADC country',
  ROA: 'Rest of Africa',
  EUR: 'Europe',
  AIS: 'Asia',
  NOR: 'North America',
  SOU: 'South or Central America',
  AUS: 'Australia and Oceania',
  OOC: 'Other',
};

export const CITIZEN: Record<string, string> = {
  SA: 'South African citizen',
  PR: 'Permanent resident',
  D: 'Dual citizenship',
  O: 'Other',
};

export const WORK_STATUS: Record<string, string> = {
  '01': 'Employed',
  '02': 'Unemployed, looking for work',
  '03': 'Not working, not looking',
  '04': 'Home-maker (not working)',
  '06': 'Scholar or student (not working)',
  '07': 'Pensioner or retired',
  '08': 'Not working: disabled',
  '09': 'Not working: no wish to work',
  '10': 'Not working: other reason',
};

export const DISABILITY: Record<string, string> = {
  '01': 'Sight',
  '02': 'Hearing',
  '03': 'Communication',
  '04': 'Physical',
  '05': 'Intellectual',
  '06': 'Emotional',
  '07': 'Multiple',
  '09': 'Not specified',
};

export const DISABILITY_RATING = ['01', '02', '03', '04', '06', '60', '70', '80'];
