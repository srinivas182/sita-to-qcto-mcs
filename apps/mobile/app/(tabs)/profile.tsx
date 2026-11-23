import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { ApiError, api, errorMessage, json } from '@/lib/api';
import { useApp } from '@/lib/app';
import { CITIZEN, NATIONALITIES, PROVINCES, type QctoCheck, TITLES, WORK_STATUS, problemsFor } from '@/core/qcto';

interface Profile {
  first_name: string;
  last_name: string;
  email: string | null;
  phone: string | null;
  id_number: string | null;
  address_line1: string | null;
  city: string | null;
  postal_code: string | null;
  home_language: string | null;
  emergency_name: string | null;
  emergency_phone: string | null;
  emergency_relationship: string | null;
  address_line2: string | null;
  province: string | null;
  title: string | null;
  middle_name: string | null;
  nationality: string | null;
  citizen_status: string | null;
  socioeconomic_status: string | null;
  postal_same: boolean;
  postal_line1: string | null;
  postal_line2: string | null;
  postal_code_postal: string | null;
  statssa_area_code: string | null;
  equity_consented: boolean;
  equity: { gender?: string | null; race?: string | null; disability?: boolean | null } | null;
}

const GENDERS: Array<[string, string]> = [['female', 'Female'], ['male', 'Male'], ['non_binary', 'Non-binary'], ['prefer_not_to_say', 'Prefer not to say']];
const RACES: Array<[string, string]> = [['african', 'African'], ['coloured', 'Coloured'], ['indian', 'Indian'], ['white', 'White'], ['other', 'Other'], ['prefer_not_to_say', 'Prefer not to say']];

function Chips({ label, options, value, onChange }: { label: string; options: Array<[string, string]>; value: string | null | undefined; onChange: (v: string) => void }) {
  return (
    <View className="gap-1.5">
      <Text className="font-medium text-small text-ink dark:text-night-ink">{label}</Text>
      <View className="flex-row flex-wrap gap-2" accessibilityRole="radiogroup" accessibilityLabel={label}>
        {options.map(([k, l]) => (
          <Pressable key={k} accessibilityRole="radio" accessibilityState={{ selected: value === k }} onPress={() => onChange(k)}
            className={`rounded-full border px-3 py-1.5 ${value === k ? 'border-primary-700 bg-primary-50 dark:border-primary-400 dark:bg-primary-900' : 'border-line dark:border-night-line'}`}>
            <Text className={`font-medium text-small ${value === k ? 'text-primary-800 dark:text-primary-100' : 'text-ink dark:text-night-ink'}`}>{l}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

/** The learner's own record: contact and emergency details they keep up to date, and their equity consent. */
export default function ProfileTab() {
  const { t, online } = useApp();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['profile'], queryFn: () => json<{ data: Profile; qcto?: QctoCheck[] }>(api.GET('/me/profile' as never)), enabled: online });

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-10 pt-4" keyboardShouldPersistTaps="handled">
        <Title>{t('profile.title')}</Title>
        {q.isError ? <Notice tone="danger">{errorMessage(q.error)}</Notice> : null}
        {q.data ? <Editor key={JSON.stringify(q.data.data)} profile={q.data.data} qcto={q.data.qcto} onSaved={(r) => qc.setQueryData(['profile'], r)} /> : null}
      </ScrollView>
    </Screen>
  );
}

function Editor({ profile: p, qcto, onSaved }: { profile: Profile; qcto: QctoCheck[] | undefined; onSaved: (r: { data: Profile; qcto?: QctoCheck[] }) => void }) {
  const { t } = useApp();
  const [form, setForm] = useState({
    address_line1: p.address_line1 ?? '', city: p.city ?? '', postal_code: p.postal_code ?? '', home_language: p.home_language ?? '',
    emergency_name: p.emergency_name ?? '', emergency_phone: p.emergency_phone ?? '', emergency_relationship: p.emergency_relationship ?? '',
    address_line2: p.address_line2 ?? '', province: p.province ?? '', title: p.title ?? '', middle_name: p.middle_name ?? '',
    nationality: p.nationality ?? '', citizen_status: p.citizen_status ?? '', socioeconomic_status: p.socioeconomic_status ?? '',
    statssa_area_code: p.statssa_area_code ?? '', postal_line1: p.postal_line1 ?? '', postal_line2: p.postal_line2 ?? '', postal_code_postal: p.postal_code_postal ?? '',
  });
  const [postalSame, setPostalSame] = useState(p.postal_same);
  const problems = problemsFor(qcto);
  const [equity, setEquity] = useState({ gender: p.equity?.gender ?? null, race: p.equity?.race ?? null, disability: p.equity?.disability ?? null });
  const [consent, setConsent] = useState(p.equity_consented);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saved, setSaved] = useState(false);
  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) => json<{ data: Profile; qcto?: QctoCheck[] }>(api.PATCH('/me/profile' as never, { body } as never)),
    onSuccess: (r) => {
      setErrors({});
      setSaved(true);
      onSaved(r);
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : { status: [errorMessage(e)] }),
  });
  const set = (k: keyof typeof form) => (v: string) => {
    setSaved(false);
    setForm({ ...form, [k]: v });
  };
  const err = (k: string) => errors[k]?.[0] ?? null;
  const blank = (o: Record<string, string>) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v === '' ? null : v]));

  return (
    <>
      <Card className="gap-1">
        <Text className="font-semibold text-section text-ink dark:text-night-ink">{p.first_name} {p.last_name}</Text>
        <Body>{[p.phone, p.email].filter(Boolean).join(' · ')}</Body>
        {p.id_number ? <Body>{t('profile.id')}: {p.id_number}</Body> : null}
        <Text className="mt-1 font-sans text-caption text-muted dark:text-night-muted">{t('profile.idLocked')}</Text>
      </Card>

      <Card className="gap-3">
        <Field label={t('profile.address')} value={form.address_line1} onChangeText={set('address_line1')} error={err('address_line1')} autoComplete="street-address" />
        <View className="flex-row gap-3">
          <View className="flex-1"><Field label={t('profile.city')} value={form.city} onChangeText={set('city')} error={err('city')} /></View>
          <View className="w-28"><Field label={t('profile.postal')} value={form.postal_code} onChangeText={set('postal_code')} keyboardType="number-pad" maxLength={4} error={err('postal_code')} /></View>
        </View>
        <Field label={t('profile.address2')} value={form.address_line2} onChangeText={set('address_line2')} error={err('address_line2')} />
        <Chips label={t('profile.province')} options={PROVINCES.map((x) => [x, x])} value={form.province} onChange={set('province')} />
        <Field label={t('profile.language')} value={form.home_language} onChangeText={set('home_language')} error={err('home_language')} />
        <Text className="mt-2 font-semibold text-body text-ink dark:text-night-ink">{t('profile.qcto')}</Text>
        <Body>{t('profile.qctoBody')}</Body>
        {problems.length ? (
          <Notice tone="warning">{`${t('profile.qctoFix')}:\n${problems.map((x) => `• ${x}`).join('\n')}`}</Notice>
        ) : null}
        <Chips label={t('profile.title2')} options={TITLES.map((x) => [x, x])} value={form.title} onChange={set('title')} />
        <Field label={t('profile.middleName')} value={form.middle_name} onChangeText={set('middle_name')} error={err('middle_name')} />
        <Chips label={t('profile.nationality')} options={NATIONALITIES} value={form.nationality} onChange={set('nationality')} />
        <Chips label={t('profile.citizen')} options={CITIZEN} value={form.citizen_status} onChange={set('citizen_status')} />
        <Chips label={t('profile.work')} options={WORK_STATUS} value={form.socioeconomic_status} onChange={set('socioeconomic_status')} />
        <Field label={t('profile.area')} value={form.statssa_area_code} onChangeText={set('statssa_area_code')} error={err('statssa_area_code')} />
        <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: postalSame }} onPress={() => { setSaved(false); setPostalSame(!postalSame); }} className="flex-row items-center gap-3 py-1">
          <View className={`h-6 w-6 rounded-md border ${postalSame ? 'border-primary-700 bg-primary-700' : 'border-line dark:border-night-line'}`} />
          <Text className="flex-1 font-sans text-body text-ink dark:text-night-ink">{t('profile.postalSame')}</Text>
        </Pressable>
        {!postalSame ? (
          <>
            <Field label={t('profile.postal1')} value={form.postal_line1} onChangeText={set('postal_line1')} error={err('postal_line1')} />
            <Field label={t('profile.postal2')} value={form.postal_line2} onChangeText={set('postal_line2')} error={err('postal_line2')} />
            <Field label={t('profile.postalCode')} value={form.postal_code_postal} onChangeText={set('postal_code_postal')} keyboardType="number-pad" maxLength={4} error={err('postal_code_postal')} />
          </>
        ) : null}
        <Text className="mt-2 font-semibold text-body text-ink dark:text-night-ink">{t('profile.emergency')}</Text>
        <Field label={t('profile.emergencyName')} value={form.emergency_name} onChangeText={set('emergency_name')} error={err('emergency_name')} />
        <Field label={t('profile.emergencyPhone')} value={form.emergency_phone} onChangeText={set('emergency_phone')} keyboardType="phone-pad" error={err('emergency_phone')} />
        <Field label={t('profile.emergencyRelationship')} value={form.emergency_relationship} onChangeText={set('emergency_relationship')} error={err('emergency_relationship')} />
        {err('status') ? <Notice tone="danger">{err('status')}</Notice> : null}
        {saved ? <Notice tone="success">{t('profile.saved')}</Notice> : null}
        <Button title={t('profile.save')} loading={save.isPending && !save.variables?.equity_consent} onPress={() => save.mutate({ ...blank(form), postal_same: postalSame })} />
      </Card>

      <Card className="gap-3">
        <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('profile.consent')}</Text>
        <Body>{p.equity_consented ? t('profile.consentOn') : t('profile.consentOff')}</Body>
        <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: consent }} onPress={() => setConsent(!consent)} className="flex-row items-center gap-3 py-1">
          <View className={`h-6 w-6 rounded-md border ${consent ? 'border-primary-700 bg-primary-700' : 'border-line dark:border-night-line'}`} />
          <Text className="flex-1 font-sans text-body text-ink dark:text-night-ink">{t('profile.consent')}</Text>
        </Pressable>
        {consent ? (
          <>
            <Chips label="Gender" options={GENDERS} value={equity.gender} onChange={(v) => setEquity({ ...equity, gender: v })} />
            <Chips label="Race (for SETA equity reports)" options={RACES} value={equity.race} onChange={(v) => setEquity({ ...equity, race: v })} />
            <Chips label="Disability" options={[['yes', 'Yes'], ['no', 'No']]} value={equity.disability === null ? null : equity.disability ? 'yes' : 'no'} onChange={(v) => setEquity({ ...equity, disability: v === 'yes' })} />
            <Button title={t('profile.save')} variant="secondary" loading={save.isPending && save.variables?.equity_consent === true}
              onPress={() => save.mutate({ equity_consent: true, equity })} />
          </>
        ) : p.equity_consented ? (
          <Button title={t('profile.withdraw')} variant="danger" loading={save.isPending && save.variables?.equity_consent === false} onPress={() => save.mutate({ equity_consent: false })} />
        ) : null}
      </Card>
    </>
  );
}
