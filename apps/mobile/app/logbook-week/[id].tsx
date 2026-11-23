import { useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { BackHeader } from '@/components/BackHeader';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { ApiError, errorMessage } from '@/lib/api';
import { useApp } from '@/lib/app';
import { returnWeek, signWeek, useWeek } from '@/lib/logbook';

/** A mentor checks a learner's week: sign it, or send entries back with what to fix. */
export default function WeekScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t } = useApp();
  const router = useRouter();
  const qc = useQueryClient();
  const q = useWeek(id);
  const [confirm, setConfirm] = useState(false);
  const [returning, setReturning] = useState(false);
  const [comment, setComment] = useState('');
  const [picked, setPicked] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'danger' | 'success'; text: string } | null>(null);
  const d = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });

  const act = async (fn: () => Promise<unknown>, done: string) => {
    setBusy(true);
    setMessage(null);
    try {
      await fn();
      void qc.invalidateQueries({ queryKey: ['mentoring-weeks'] });
      void q.refetch();
      setMessage({ tone: 'success', text: done });
    } catch (e) {
      setMessage({ tone: 'danger', text: e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e) });
    } finally {
      setBusy(false);
    }
  };

  const w = q.data;
  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" keyboardShouldPersistTaps="handled">
        <BackHeader />
        {!w ? (
          <Body>{t('common.loading')}</Body>
        ) : (
          <>
            <Title>{t('mentor.week', { learner: w.learner ?? '', date: d(w.week_start) })}</Title>
            {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}
            <Card className="gap-1">
              {w.entries.map((e) => {
                const on = picked.includes(e.id);
                const row = (
                  <View className="gap-1 border-b border-line py-2">
                    <Text className="font-medium text-body text-ink dark:text-night-ink">
                      {returning ? (on ? '☑ ' : '☐ ') : ''}
                      {d(e.work_date)} · {e.activity} · {Math.round((e.minutes / 60) * 10) / 10} h
                    </Text>
                    {e.note ? <Text className="font-sans text-small text-ink dark:text-night-ink">{e.note}</Text> : null}
                    <Text className="font-sans text-small text-muted dark:text-night-muted">
                      {e.module}
                      {e.distance_m !== null ? (e.location_ok ? ' · on site' : ` · ${e.distance_m} m from site`) : ''}
                      {e.file_ids?.length ? ` · 📷 ${e.file_ids.length}` : ''}
                    </Text>
                  </View>
                );
                return returning ? (
                  <Pressable key={e.id} accessibilityRole="checkbox" accessibilityState={{ checked: on }} onPress={() => setPicked(on ? picked.filter((x) => x !== e.id) : [...picked, e.id])}>
                    {row}
                  </Pressable>
                ) : (
                  <View key={e.id}>{row}</View>
                );
              })}
            </Card>
            {w.status === 'signed' || message?.tone === 'success' ? (
              <Button title={t('common.done')} onPress={() => router.back()} />
            ) : returning ? (
              <Card className="gap-3">
                <Body>{t('mentor.sendBackPick')}</Body>
                <Field label={t('mentor.sendBackWhat')} multiline value={comment} onChangeText={setComment} />
                <Button title={t('mentor.sendBack')} variant="danger" loading={busy} disabled={comment.trim().length < 5}
                  onPress={() => void act(() => returnWeek(w.id, comment, picked), t('mentor.sentBack'))} />
                <Button title={t('common.back')} variant="secondary" onPress={() => setReturning(false)} />
              </Card>
            ) : (
              <Card className="gap-3">
                <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: confirm }} onPress={() => setConfirm(!confirm)}
                  className={`min-h-11 flex-row items-center gap-3 rounded-xl border px-4 py-3 ${confirm ? 'border-2 border-primary-600 bg-primary-50' : 'border-line'}`}>
                  <Text className="text-section">{confirm ? '☑' : '☐'}</Text>
                  <Text className="flex-1 font-sans text-body text-ink">{t('mentor.confirm')}</Text>
                </Pressable>
                <Button title={t('mentor.sign')} loading={busy} disabled={!confirm} onPress={() => void act(() => signWeek(w.id), t('mentor.signed'))} />
                <Button title={t('mentor.sendBack')} variant="secondary" onPress={() => setReturning(true)} />
              </Card>
            )}
          </>
        )}
      </ScrollView>
    </Screen>
  );
}
