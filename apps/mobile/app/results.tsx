import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { BackHeader, Pill } from '@/components/BackHeader';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { ApiError, errorMessage } from '@/lib/api';
import { type Latest, appeal, declareEisa, shareStatement, useMyEisa, useMyResults, useMyStatements } from '@/lib/assess';
import { useApp } from '@/lib/app';

const tone = { competent: 'success', nyc: 'danger', in_progress: 'warning', not_assessed: 'neutral', no_assessment: 'neutral' } as const;

/** The learner's results per module, feedback, appeals and the Statement of Results. */
export default function ResultsScreen() {
  const { t } = useApp();
  const qc = useQueryClient();
  const q = useMyResults();
  const sors = useMyStatements();
  const eisa = useMyEisa();
  const [agree, setAgree] = useState(false);
  const [signName, setSignName] = useState('');
  const [appealing, setAppealing] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'danger' | 'success'; text: string } | null>(null);
  const d = (iso: string) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });

  const send = async (id: string) => {
    setBusy(true);
    try {
      await appeal(id, reason);
      setAppealing(null);
      setReason('');
      setMessage({ tone: 'success', text: t('results.appealSent') });
      void qc.invalidateQueries({ queryKey: ['my-results'] });
    } catch (e) {
      setMessage({ tone: 'danger', text: e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e) });
    } finally {
      setBusy(false);
    }
  };

  const sign = async (id: string) => {
    setBusy(true);
    try {
      await declareEisa(id, signName);
      setMessage({ tone: 'success', text: t('eisa.signed') });
      void qc.invalidateQueries({ queryKey: ['my-eisa'] });
    } catch (e) {
      setMessage({ tone: 'danger', text: e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e) });
    } finally {
      setBusy(false);
    }
  };

  const latestBlock = (l: Latest) => (
    <View className="gap-1">
      <Text className="font-sans text-small text-muted dark:text-night-muted">
        {t('assess.attempt', { n: l.attempt, outcome: t(`outcome.${l.outcome}` as never) })} · {d(l.assessed_on)}{l.score !== null ? ` · ${l.score}%` : ''}
      </Text>
      {l.feedback ? <Text className="font-sans text-small text-ink dark:text-night-ink">{l.feedback}</Text> : null}
      {l.remediation ? <Text className="font-sans text-small text-ink dark:text-night-ink">{t('results.toWorkOn', { text: l.remediation })}</Text> : null}
      {l.appeal ? (
        <Text className="font-sans text-small text-muted dark:text-night-muted">
          {l.appeal.status === 'open' ? t('results.appealOpen', { date: d(l.appeal.decide_by) })
            : t('results.appealDone', { outcome: t(l.appeal.status === 'overturned' ? 'results.appealOverturned' : 'results.appealUpheld'), note: l.appeal.decision_note ?? '' })}
        </Text>
      ) : l.can_appeal ? (
        appealing === l.id ? (
          <View className="gap-2">
            <Field label={t('results.appealWhy')} multiline value={reason} onChangeText={setReason} />
            <Button title={t('results.appealSend')} loading={busy} disabled={reason.trim().length < 10} onPress={() => void send(l.id)} />
            <Button title={t('common.cancel')} variant="secondary" onPress={() => setAppealing(null)} />
          </View>
        ) : (
          <View className="items-start"><Button title={t('results.appeal')} variant="ghost" onPress={() => setAppealing(l.id)} /></View>
        )
      ) : null}
    </View>
  );

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-10 pt-4" keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={q.isRefetching} onRefresh={() => void Promise.all([q.refetch(), sors.refetch(), eisa.refetch()])} />}>
        <BackHeader />
        <Title>{t('results.title')}</Title>
        <Body>{t('results.body')}</Body>
        {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}
        {eisa.data?.data.map((r) => (
          <Card key={r.id} className="gap-2">
            <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('eisa.title', { date: d(r.eisa_on) })}</Text>
            <Text className="font-sans text-small text-muted dark:text-night-muted">
              {r.assessment_centre_name ?? r.assessment_centre ?? t('eisa.centreTbc')} · {r.status === 'registered' ? t('eisa.registered') : t('eisa.pending')}
            </Text>
            {r.declared_at ? (
              <Text className="font-sans text-small text-success">{t('eisa.declared', { date: d(r.declared_at) })}</Text>
            ) : r.status === 'draft' ? (
              <View className="gap-2">
                <Text className="font-sans text-small text-ink dark:text-night-ink">{eisa.data.declaration}</Text>
                <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: agree }} onPress={() => setAgree(!agree)} className="flex-row items-center gap-3 py-1">
                  <View className={`h-6 w-6 rounded-md border ${agree ? 'border-primary-700 bg-primary-700' : 'border-line dark:border-night-line'}`} />
                  <Text className="flex-1 font-sans text-body text-ink dark:text-night-ink">{t('eisa.agree')}</Text>
                </Pressable>
                <Field label={t('eisa.name', { name: eisa.data.name })} value={signName} onChangeText={setSignName} autoComplete="name" />
                <Button title={t('eisa.sign')} loading={busy} disabled={!agree || !signName.trim()} onPress={() => void sign(r.id)} />
              </View>
            ) : null}
          </Card>
        ))}
        {sors.data?.filter((s) => s.status === 'issued').map((s) => (
          <Card key={s.id} className="gap-2">
            <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('results.sor', { number: s.number })}</Text>
            <Text className="font-sans text-small text-muted dark:text-night-muted">{t('results.sorBody', { date: d(s.issued_at) })}</Text>
            <View className="items-start"><Button title={t('results.download')} onPress={() => void shareStatement(s).catch((e: unknown) => setMessage({ tone: 'danger', text: errorMessage(e) }))} /></View>
          </Card>
        ))}
        {q.data && q.data.length === 0 ? <Notice>{t('results.none')}</Notice> : null}
        {q.data?.map((c) => (
          <Card key={c.enrolment_id} className="gap-3">
            <Text className="font-semibold text-section text-ink dark:text-night-ink">{c.cohort}</Text>
            {c.modules.map((m) => (
              <View key={m.module_id} className="gap-1 border-b border-line pb-3">
                <View className="flex-row items-center justify-between gap-2">
                  <Text className="flex-1 font-medium text-body text-ink dark:text-night-ink">{m.code} {m.title}</Text>
                  <Pill text={t(`state.${m.state}` as never)} tone={tone[m.state]} />
                </View>
                {m.instruments.filter((i) => i.latest).map((i) => (
                  <View key={i.instrument_id} className="gap-1">
                    <Text className="font-sans text-small text-ink dark:text-night-ink">{i.title}</Text>
                    {latestBlock(i.latest!)}
                  </View>
                ))}
              </View>
            ))}
          </Card>
        ))}
      </ScrollView>
    </Screen>
  );
}
