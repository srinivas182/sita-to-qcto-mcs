import { useQueryClient } from '@tanstack/react-query';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useRef, useState } from 'react';
import { Image, Pressable, ScrollView, Text, View } from 'react-native';
import { BackHeader } from '@/components/BackHeader';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { localDate } from '@/core/entryHash';
import { useApp } from '@/lib/app';
import { syncLogbook, useMyLogbook, waiting, writeEntry } from '@/lib/logbook';

function Choice({ label, on, onPress }: { label: string; on: boolean; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="radio" accessibilityState={{ checked: on }} onPress={onPress}
      className={`min-h-11 justify-center rounded-xl border px-4 py-2 ${on ? 'border-2 border-primary-600 bg-primary-50' : 'border-line'}`}>
      <Text className="font-sans text-body text-ink">{label}</Text>
    </Pressable>
  );
}

/**
 * Log a day on site. Saved on the phone first (time, distance, photos, chained fingerprint)
 * and sent when there is signal. With ?replaces=<id> it corrects an entry sent back.
 */
export default function NewEntryScreen() {
  const { replaces } = useLocalSearchParams<{ replaces?: string }>();
  const { t, online, syncNow } = useApp();
  const router = useRouter();
  const qc = useQueryClient();
  const lb = useMyLogbook(online).data;
  const old = replaces ? lb?.entries.find((e) => e.id === replaces) : undefined;
  const [moduleId, setModuleId] = useState<string>(old?.module_id ?? lb?.modules[0]?.id ?? '');
  const [activity, setActivity] = useState<string>(old?.activity_key ?? '');
  const [daysBack, setDaysBack] = useState(0);
  const [hours, setHours] = useState(old ? String(Math.floor(old.minutes / 60)) : '8');
  const [mins, setMins] = useState(old ? String(old.minutes % 60) : '0');
  const [note, setNote] = useState(old?.note ?? '');
  const [photos, setPhotos] = useState<string[]>([]);
  const [camera, setCamera] = useState(false);
  const [permission, request] = useCameraPermissions();
  const cam = useRef<CameraView>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'danger' | 'success' | 'info'; text: string } | null>(null);

  const module = lb?.modules.find((m) => m.id === (moduleId || lb.modules[0]?.id));
  const act = module?.activities.find((a) => a.key === activity);
  const date = old ? old.work_date : localDate(new Date(Date.now() - daysBack * 86_400_000));
  const minutes = (Number(hours) || 0) * 60 + (Number(mins) || 0);

  const save = async () => {
    setMessage(null);
    if (!module || !act) return setMessage({ tone: 'danger', text: t('logbook.activity') });
    if (minutes < 15) return setMessage({ tone: 'danger', text: t('logbook.tooShort') });
    if (minutes > 720) return setMessage({ tone: 'danger', text: t('logbook.tooLong') });
    if (act.photo && photos.length === 0) return setMessage({ tone: 'danger', text: t('logbook.photoNeeded') });
    setBusy(true);
    try {
      await writeEntry({ moduleId: module.id, activity: act.key, date, minutes, note, photos, replaces }, lb?.last_hash ?? null);
      if (online) {
        const left = await syncLogbook();
        void syncNow();
        void qc.invalidateQueries({ queryKey: ['my-logbook'] });
        const refused = waiting().find((w) => w.status === 'conflict');
        setMessage(left === 0 ? { tone: 'success', text: t('logbook.sent') } : refused ? { tone: 'danger', text: t('logbook.refused', { reason: refused.error ?? '' }) } : { tone: 'info', text: t('logbook.saved') });
      } else {
        setMessage({ tone: 'info', text: t('logbook.saved') });
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
        <View className="absolute inset-x-0 top-14 items-center px-6">
          <Text className="rounded-xl bg-black/60 px-3 py-2 text-center font-sans text-small text-white">{t('logbook.photoPeople')}</Text>
        </View>
        <View className="absolute inset-x-0 bottom-10 items-center gap-3">
          <Pressable accessibilityRole="button" accessibilityLabel={t('docs.take')} className="h-20 w-20 rounded-full border-4 border-white bg-white/20"
            onPress={() => void cam.current?.takePictureAsync({ quality: 0.6 }).then((p) => { if (p) setPhotos((x) => [...x, p.uri]); setCamera(false); })} />
          <Button title={t('common.cancel')} variant="secondary" onPress={() => setCamera(false)} />
        </View>
      </View>
    );
  }

  if (!lb || !module) {
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
        <Title>{t('logbook.new')}</Title>
        {old ? <Notice>{t('logbook.correcting', { date: old.work_date })}</Notice> : null}
        {old?.comment ? <Notice tone="warning">{old.comment}</Notice> : null}
        {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}
        {message?.tone === 'success' || message?.tone === 'info' ? (
          <Button title={t('common.done')} onPress={() => router.back()} />
        ) : (
          <>
            {lb.modules.length > 1 ? (
              <Card className="gap-2">
                <Text className="font-medium text-body text-ink dark:text-night-ink">{t('logbook.module')}</Text>
                {lb.modules.map((m) => <Choice key={m.id} label={`${m.code} ${m.title}`} on={m.id === module.id} onPress={() => { setModuleId(m.id); setActivity(''); }} />)}
              </Card>
            ) : null}
            <Card className="gap-2">
              <Text className="font-medium text-body text-ink dark:text-night-ink">{t('logbook.activity')}</Text>
              <View accessibilityRole="radiogroup" className="gap-2">
                {module.activities.map((a) => <Choice key={a.key} label={a.photo ? `${a.title} 📷` : a.title} on={a.key === activity} onPress={() => setActivity(a.key)} />)}
              </View>
            </Card>
            {!old ? (
              <Card className="gap-2">
                <Text className="font-medium text-body text-ink dark:text-night-ink">{t('logbook.date')}</Text>
                <View accessibilityRole="radiogroup" className="flex-row flex-wrap gap-2">
                  {[0, 1, 2, 3].map((n) => (
                    <Choice key={n} label={n === 0 ? t('logbook.today') : n === 1 ? t('logbook.yesterday') : t('logbook.daysAgo', { n })} on={daysBack === n} onPress={() => setDaysBack(n)} />
                  ))}
                </View>
              </Card>
            ) : null}
            <Card className="gap-3">
              <View className="flex-row gap-3">
                <View className="flex-1"><Field label={t('logbook.hoursWorked')} keyboardType="number-pad" value={hours} onChangeText={setHours} /></View>
                <View className="flex-1"><Field label={t('logbook.minutesWorked')} keyboardType="number-pad" value={mins} onChangeText={setMins} /></View>
              </View>
              <Field label={t('logbook.note')} multiline value={note} onChangeText={setNote} />
              <Text className="font-sans text-small text-muted dark:text-night-muted">{t('logbook.noteHint')}</Text>
              {module.evidence_note ? <Text className="font-sans text-small text-muted dark:text-night-muted">{module.evidence_note}</Text> : null}
            </Card>
            <Card className="gap-2">
              <Text className="font-medium text-body text-ink dark:text-night-ink">{t('logbook.photo')}</Text>
              <Text className="font-sans text-small text-muted dark:text-night-muted">{t('logbook.photoPeople')}</Text>
              <View className="flex-row flex-wrap gap-2">
                {photos.map((p) => <Image key={p} source={{ uri: p }} accessibilityLabel={t('logbook.photo')} style={{ width: 80, height: 80, borderRadius: 12 }} />)}
              </View>
              {photos.length < 6 ? <Button title={t('logbook.addPhoto')} variant="secondary" onPress={() => setCamera(true)} /> : null}
            </Card>
            <Button title={t('logbook.save')} loading={busy} onPress={() => void save()} />
          </>
        )}
      </ScrollView>
    </Screen>
  );
}
