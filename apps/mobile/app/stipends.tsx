import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { BackHeader, Pill } from '@/components/BackHeader';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { rand, validAccount } from '@/core/stipend';
import { ApiError, errorMessage } from '@/lib/api';
import { useApp } from '@/lib/app';
import { saveMyBank, useBanks, useMyStipends } from '@/lib/stipends';

const tone = { paid: 'success', pending: 'warning', held: 'danger' } as const;

/** The learner's stipend per month and the bank account it is paid into. */
export default function StipendsScreen() {
  const { t } = useApp();
  const qc = useQueryClient();
  const q = useMyStipends();
  const banks = useBanks();
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({ holder_name: '', bank: '', account_number: '', account_type: 'savings' });
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'danger' | 'success'; text: string } | null>(null);
  const month = (ym: string) => new Date(`${ym}-01T00:00:00Z`).toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });

  const save = async () => {
    const account = validAccount(form.account_number);
    if (!account) {
      setMessage({ tone: 'danger', text: t('stipends.badAccount') });
      return;
    }
    setBusy(true);
    try {
      await saveMyBank({ ...form, account_number: account });
      setMessage({ tone: 'success', text: t('stipends.saved') });
      setEditing(false);
      setForm({ holder_name: '', bank: '', account_number: '', account_type: 'savings' });
      void qc.invalidateQueries({ queryKey: ['my-stipends'] });
      void qc.invalidateQueries({ queryKey: ['my-bank'] });
    } catch (e) {
      setMessage({ tone: 'danger', text: e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e) });
    } finally {
      setBusy(false);
    }
  };

  const bank = q.data?.bank;
  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-10 pt-4" keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={q.isRefetching} onRefresh={() => void q.refetch()} />}>
        <BackHeader />
        <Title>{t('stipends.title')}</Title>
        <Body>{t('stipends.body')}</Body>
        {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}

        <Card className="gap-2">
          <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('stipends.bank')}</Text>
          {bank ? (
            <Text className="font-sans text-body text-ink dark:text-night-ink">{`${bank.bank_name} ${bank.account} · ${bank.holder_name}`}</Text>
          ) : (
            <Notice tone="warning">{t('stipends.noBank')}</Notice>
          )}
          {!editing ? (
            <Button title={bank ? t('stipends.changeBank') : t('stipends.addBank')} variant="secondary" onPress={() => setEditing(true)} />
          ) : (
            <View className="gap-3">
              <Field label={t('stipends.holder')} value={form.holder_name} onChangeText={(v) => setForm({ ...form, holder_name: v })} autoComplete="name" />
              <Text className="font-medium text-small text-ink dark:text-night-ink">{t('stipends.whichBank')}</Text>
              <View className="flex-row flex-wrap gap-2" accessibilityRole="radiogroup" accessibilityLabel={t('stipends.whichBank')}>
                {banks.data?.banks.filter((b) => b.key !== 'other').map((b) => (
                  <Pressable key={b.key} accessibilityRole="radio" accessibilityState={{ selected: form.bank === b.key }} onPress={() => setForm({ ...form, bank: b.key })}
                    className={`rounded-full border px-3 py-2 ${form.bank === b.key ? 'border-primary-700 bg-primary-50 dark:border-primary-400 dark:bg-primary-900' : 'border-line dark:border-night-line'}`}>
                    <Text className={`font-medium text-small ${form.bank === b.key ? 'text-primary-800 dark:text-primary-100' : 'text-ink dark:text-night-ink'}`}>{b.name}</Text>
                  </Pressable>
                ))}
              </View>
              <Field label={t('stipends.account')} value={form.account_number} onChangeText={(v) => setForm({ ...form, account_number: v })} keyboardType="number-pad" maxLength={20} autoComplete="off" />
              <View className="flex-row flex-wrap gap-2" accessibilityRole="radiogroup" accessibilityLabel={t('stipends.type')}>
                {(['savings', 'cheque', 'transmission'] as const).map((k) => (
                  <Pressable key={k} accessibilityRole="radio" accessibilityState={{ selected: form.account_type === k }} onPress={() => setForm({ ...form, account_type: k })}
                    className={`rounded-full border px-3 py-2 ${form.account_type === k ? 'border-primary-700 bg-primary-50 dark:border-primary-400 dark:bg-primary-900' : 'border-line dark:border-night-line'}`}>
                    <Text className={`font-medium text-small ${form.account_type === k ? 'text-primary-800 dark:text-primary-100' : 'text-ink dark:text-night-ink'}`}>{t(`stipends.type.${k}`)}</Text>
                  </Pressable>
                ))}
              </View>
              <Button title={t('stipends.saveBank')} loading={busy} disabled={!form.holder_name.trim() || !form.bank || !form.account_number} onPress={() => void save()} />
              <Button title={t('common.cancel')} variant="ghost" onPress={() => setEditing(false)} />
            </View>
          )}
        </Card>

        {q.data?.data.length === 0 ? <Body>{t('stipends.none')}</Body> : null}
        {q.data?.data.map((s) => (
          <Card key={s.month} className="gap-1.5">
            <View className="flex-row items-center justify-between">
              <Text className="font-semibold text-body text-ink dark:text-night-ink">{month(s.month)}</Text>
              <Pill text={t(`stipends.status.${s.status}`)} tone={tone[s.status]} />
            </View>
            <Text className="font-sans text-body text-ink dark:text-night-ink">{s.status === 'held' ? '—' : rand(s.amount_cents)}</Text>
            {s.attendance_pct != null ? <Text className="font-sans text-small text-muted dark:text-night-muted">{t('stipends.attendance', { pct: String(s.attendance_pct) })}</Text> : null}
            {s.reason ? <Text className="font-sans text-small text-muted dark:text-night-muted">{s.reason}</Text> : null}
          </Card>
        ))}
      </ScrollView>
    </Screen>
  );
}
