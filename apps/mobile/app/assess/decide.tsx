import { CameraView, useCameraPermissions } from 'expo-camera';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Image, Pressable, ScrollView, Text, View } from 'react-native';
import { BackHeader, Pill } from '@/components/BackHeader';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { recordDecision, syncDecisions, useLearnerDetail, waitingDecisions } from '@/lib/assess';
import { useApp } from '@/lib/app';

/** Observe or record a test on site: tick each criterion, add photos, feedback. Saved on the phone first. */
export default function DecideScreen() {
  const { cohort, enrolment, instrument } = useLocalSearchParams<{ cohort: string; enrolment: string; instrument: string }>();
  const { t, online, syncNow } = useApp();
  const router = useRouter();
  const d = useLearnerDetail(cohort, enrolment, online).data;
  const inst = d?.instruments.find((i) => i.id === instrument);
  const [met, setMet] = useState<Record<string, boolean>>({});
  const [comments, setComments] = useState<Record<string, string>>({});
  const [score, setScore] = useState('');
  const [feedback, setFeedback] = useState('');
  const [remediation, setRemediation] = useState('');
  const [photos, setPhotos] = useState<string[]>([]);
  const [camera, setCamera] = useState(false);
  const [permission, request] = useCameraPermissions();
  const cam = useRef<CameraView>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'danger' | 'success' | 'info'; text: string } | null>(null);
  const test = inst?.method === 'test';
  const taken = d?.online?.[instrument];
  useEffect(() => {
    if (taken?.latest?.score_pct != null) setScore((s) => (s === '' ? String(taken.latest?.score_pct) : s));
  }, [taken?.latest?.score_pct]);
  const outcome = useMemo(() => {
    if (!inst) return 'nyc';
    const all = inst.criteria.every((c) => met[c.key]);
    return all && (!test || (score !== '' && Number(score) >= (inst.pass_mark ?? 0))) ? 'competent' : 'nyc';
  }, [inst, met, score, test]);

  const save = async () => {
    if (!inst || !d) return;
    if (outcome === 'nyc' && !feedback.trim()) return setMessage({ tone: 'danger', text: t('assess.feedbackNeeded') });
    setBusy(true);
    setMessage(null);
    try {
      await recordDecision({ enrolmentId: enrolment, instrumentId: inst.id, learner: d.name ?? '', title: inst.title, outcome, score: test ? Number(score) : null,
        criteria: inst.criteria.map((c) => ({ key: c.key, met: !!met[c.key], comment: comments[c.key]?.trim() || null })), feedback, remediation, photos });
      if (online) {
        const left = await syncDecisions();
        void syncNow();
        const refused = waitingDecisions().find((w) => w.status === 'conflict');
        setMessage(left === 0 ? { tone: 'success', text: t('assess.sent') } : refused ? { tone: 'danger', text: t('assess.refused', { reason: refused.error ?? '' }) } : { tone: 'info', text: t('assess.saved') });
      } else {
        setMessage({ tone: 'info', text: t('assess.saved') });
      }
    } catch (e) {
      setMessage({ tone: 'danger', text: e instanceof Error ? e.message : String(e) });
    } finally {
      setBusy(false);
    }
  };

  if (camera) {
    if (!permission?.granted) {
      return (
        <Screen>
          <View className="flex-1 justify-center gap-4"><Body>{t('docs.cameraWhy')}</Body></View>
          <Button title={t('docs.allowCamera')} onPress={() => void request()} />
        </Screen>
      );
    }
    return (
      <View className="flex-1 bg-black">
        <CameraView ref={cam} style={{ flex: 1 }} />
        <View className="absolute inset-x-0 bottom-10 items-center gap-3">
          <Pressable accessibilityRole="button" accessibilityLabel={t('docs.take')} className="h-20 w-20 rounded-full border-4 border-white bg-white/20"
            onPress={() => void cam.current?.takePictureAsync({ quality: 0.6 }).then((p) => { if (p) setPhotos((x) => [...x, p.uri]); setCamera(false); })} />
          <Button title={t('common.cancel')} variant="secondary" onPress={() => setCamera(false)} />
        </View>
      </View>
    );
  }

  if (!inst || !d) {
    return (
      <Screen>
        <BackHeader />
        <Body>{online ? t('common.loading') : t('home.offline')}</Body>
      </Screen>
    );
  }

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" keyboardShouldPersistTaps="handled">
        <BackHeader />
        <Title>{inst.title}</Title>
        <Body>{d.name}</Body>
        {inst.instructions ? <Notice>{inst.instructions}</Notice> : null}
        {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}
        {message?.tone === 'success' || message?.tone === 'info' ? (
          <Button title={t('common.done')} onPress={() => router.back()} />
        ) : (
          <>
            {test ? (
              <Card className="gap-2">
                {taken ? <Notice tone="info">{taken.latest ? t('assess.onlineTaken', { task: taken.task_title, n: String(taken.latest.number), score: String(taken.latest.score_pct ?? '—') }) : t('assess.onlineNotTaken', { task: taken.task_title })}</Notice> : null}
                <Field label={t('assess.score', { pass: inst.pass_mark ?? 0 })} keyboardType="number-pad" value={score} onChangeText={setScore} />
              </Card>
            ) : null}
            <Card className="gap-3">
              <Text className="font-medium text-body text-ink dark:text-night-ink">{t('assess.criteria')}</Text>
              {inst.criteria.map((c) => (
                <View key={c.key} className="gap-2 border-b border-line pb-3">
                  <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: !!met[c.key] }} onPress={() => setMet({ ...met, [c.key]: !met[c.key] })}
                    className={`min-h-11 flex-row items-center gap-3 rounded-xl border px-4 py-3 ${met[c.key] ? 'border-2 border-primary-600 bg-primary-50' : 'border-line'}`}>
                    <Text className="text-section">{met[c.key] ? '☑' : '☐'}</Text>
                    <Text className="flex-1 font-sans text-body text-ink">{c.text}</Text>
                  </Pressable>
                  <Field label={t('assess.comment')} value={comments[c.key] ?? ''} onChangeText={(v) => setComments({ ...comments, [c.key]: v })} />
                </View>
              ))}
              <View className="items-start"><Pill text={t('assess.outcome', { outcome: t(`outcome.${outcome}` as never) })} tone={outcome === 'competent' ? 'success' : 'danger'} /></View>
            </Card>
            <Card className="gap-3">
              <Field label={outcome === 'nyc' ? t('assess.feedbackNeeded') : t('assess.feedback')} multiline value={feedback} onChangeText={setFeedback} />
              {outcome === 'nyc' ? <Field label={t('assess.remediation')} multiline value={remediation} onChangeText={setRemediation} /> : null}
              <Text className="font-sans text-small text-muted dark:text-night-muted">{t('logbook.noteHint')}</Text>
            </Card>
            <Card className="gap-2">
              <Text className="font-medium text-body text-ink dark:text-night-ink">{t('assess.evidence')}</Text>
              <View className="flex-row flex-wrap gap-2">
                {photos.map((p) => <Image key={p} source={{ uri: p }} accessibilityLabel={t('assess.evidence')} style={{ width: 80, height: 80, borderRadius: 12 }} />)}
              </View>
              {photos.length < 10 ? <Button title={t('logbook.addPhoto')} variant="secondary" onPress={() => setCamera(true)} /> : null}
            </Card>
            <Button title={t('assess.record', { outcome: t(`outcome.${outcome}` as never) })} loading={busy} onPress={() => void save()} />
          </>
        )}
      </ScrollView>
    </Screen>
  );
}
