import Feather from '@expo/vector-icons/Feather';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Linking, Pressable, ScrollView, Text, View } from 'react-native';
import { AgreementText } from '@/components/AgreementText';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { ApiError, api, errorMessage, json } from '@/lib/api';
import { CHANNEL, type MyAgreement } from '@/lib/agreements';
import { useApp } from '@/lib/app';

/** Read and sign in the app. A one-time PIN still confirms it is the signer. */
export default function SignInApp() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t } = useApp();
  const router = useRouter();
  const qc = useQueryClient();
  const key = ['my-agreements', id];
  const q = useQuery({ queryKey: key, queryFn: () => json<{ data: MyAgreement }>(api.GET(`/me/agreements/${id}` as never)).then((r) => r.data) });
  const [sentBy, setSentBy] = useState<string | null>(null);
  const [form, setForm] = useState({ code: '', name: '', agree: false });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const fail = (e: unknown) => setErrors(e instanceof ApiError ? e.fields : { status: [errorMessage(e)] });
  const code = useMutation({
    mutationFn: () => json<{ data: { channel: string } }>(api.POST(`/me/agreements/${id}/code` as never, {} as never)),
    onSuccess: (r) => {
      setErrors({});
      setSentBy(CHANNEL[r.data.channel] ?? r.data.channel);
    },
    onError: fail,
  });
  const sign = useMutation({
    mutationFn: () => json<{ data: MyAgreement }>(api.POST(`/me/agreements/${id}/sign` as never, { body: form } as never)),
    onSuccess: (r) => {
      setErrors({});
      qc.setQueryData(key, r.data);
      void qc.invalidateQueries({ queryKey: ['my-agreements'], exact: true });
    },
    onError: fail,
  });
  const err = (k: string) => errors[k]?.[0] ?? null;
  const a = q.data;

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" keyboardShouldPersistTaps="handled">
        <View className="items-start">
          <Button title={t('common.back')} variant="ghost" icon={<Feather name="chevron-left" size={18} color="#4338ca" />} onPress={() => router.back()} />
        </View>
        {q.isError ? <Notice tone="danger">{errorMessage(q.error)}</Notice> : null}
        {a ? (
          <>
            <View className="gap-1">
              <Body>{t('sign.from', { provider: a.provider })}</Body>
              <Title>{a.title}</Title>
            </View>
            <Card>
              <AgreementText text={a.body} />
            </Card>

            {a.you.status === 'signed' ? (
              <Card className="gap-2">
                <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('sign.done', { date: a.you.signed_at ? new Date(a.you.signed_at).toLocaleString() : '' })}</Text>
                <Body>{t('sign.doneBody')}</Body>
                <Pressable accessibilityRole="link" onPress={() => void Linking.openURL(a.verify_url)}>
                  <Text className="font-medium text-body text-primary-700 underline dark:text-primary-300">{a.verify_url}</Text>
                </Pressable>
              </Card>
            ) : !a.can_sign ? (
              <Notice tone="info">{a.agreement_status === 'sent' ? t('sign.advanced') : t('sign.closed')}</Notice>
            ) : (
              <Card className="gap-3">
                <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('sign.as', { name: a.you.name })}</Text>
                <Body>{t('sign.read')} {t('sign.pinTo', { to: a.you.pin_to ?? '' })}</Body>
                {err('status') ? <Notice tone="danger">{err('status')}</Notice> : null}
                {!sentBy ? (
                  <>
                    {err('code') ? <Notice tone="danger">{err('code')}</Notice> : null}
                    <Button title={t('sign.sendPin')} loading={code.isPending} onPress={() => code.mutate()} />
                  </>
                ) : (
                  <>
                    <Body>{t('sign.pinSent', { channel: sentBy })}</Body>
                    <Field label={t('sign.pin')} keyboardType="number-pad" textContentType="oneTimeCode" autoComplete="sms-otp" maxLength={6}
                      value={form.code} onChangeText={(v) => setForm({ ...form, code: v })} error={err('code')} />
                    <Field label={t('sign.name')} autoComplete="name" value={form.name} onChangeText={(v) => setForm({ ...form, name: v })}
                      error={err('name') ?? null} placeholder={a.you.name} />
                    <Text className="font-sans text-caption text-muted dark:text-night-muted">{t('sign.nameHint', { name: a.you.name })}</Text>
                    <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: form.agree }} onPress={() => setForm({ ...form, agree: !form.agree })} className="flex-row items-start gap-3 py-1">
                      <View className={`mt-0.5 h-6 w-6 items-center justify-center rounded-md border ${form.agree ? 'border-primary-700 bg-primary-700' : 'border-line dark:border-night-line'}`}>
                        {form.agree ? <Feather name="check" size={16} color="#fff" /> : null}
                      </View>
                      <Text className="flex-1 font-sans text-body text-ink dark:text-night-ink">{t('sign.agree')}</Text>
                    </Pressable>
                    <Button title={t('sign.sign')} disabled={!form.agree || form.code.length < 6 || !form.name} loading={sign.isPending} onPress={() => sign.mutate()} />
                    <Button title={t('signIn.resend')} variant="ghost" disabled={code.isPending} onPress={() => code.mutate()} />
                  </>
                )}
              </Card>
            )}
          </>
        ) : null}
      </ScrollView>
    </Screen>
  );
}
