import * as Clipboard from "expo-clipboard";
import * as Device from "expo-device";
import { useEffect, useState } from "react";
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  Text,
  View,
} from "react-native";
import {
  Body,
  Brand,
  Button,
  Field,
  Notice,
  Screen,
  Title,
} from "@/components/ui";
import type { TokenPair } from "@/core/session";
import { api, errorMessage, json } from "@/lib/api";
import { useApp } from "@/lib/app";
import { devicePublicKey } from "@/lib/session";

/** Phone PIN sign-in. The device and its public key are registered in the same step. */
export default function SignIn() {
  const { t, tenant, notice, dismissNotice, signedIn, changeTenant } = useApp();
  const [identifier, setIdentifier] = useState("");
  const [challenge, setChallenge] = useState<{
    id: string;
    resendAt: number;
  } | null>(null);
  const [pin, setPin] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [now, setNow] = useState(Date.now());

  useEffect(() => {
    if (!challenge) return;
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, [challenge]);

  const send = async () => {
    setBusy(true);
    setError(null);
    dismissNotice();
    try {
      const r = await json<{ challenge_id: string; resend_after: number }>(
        api.POST("/auth/otp/request", { body: { identifier } }),
      );
      setChallenge({
        id: r.challenge_id,
        resendAt: Date.now() + r.resend_after * 1000,
      });
      setPin("");
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  // WhatsApp's "Copy code" button puts the PIN on the clipboard: one tap to use it.
  const paste = async () => {
    const text = (await Clipboard.getStringAsync()).trim();
    const code = text.match(/\b(\d{6})\b/)?.[1];
    if (!code) return;
    setPin(code);
    void verify(code);
  };

  const verify = async (code = pin) => {
    if (!challenge || code.length !== 6) return;
    setBusy(true);
    setError(null);
    try {
      const device = {
        platform: Platform.OS === "ios" ? "ios" : "android",
        name:
          Device.modelName ??
          (Platform.OS === "ios" ? "iPhone" : "Android phone"),
        public_key: await devicePublicKey(),
      };
      const pair = await json<TokenPair>(
        api.POST("/auth/otp/verify", {
          body: { challenge_id: challenge.id, code, device } as never,
        }),
      );
      await signedIn(pair);
    } catch (e) {
      setError(errorMessage(e));
      setBusy(false);
    }
  };

  const wait = challenge
    ? Math.max(0, Math.ceil((challenge.resendAt - now) / 1000))
    : 0;

  return (
    <Screen>
      <KeyboardAvoidingView
        behavior={Platform.OS === "ios" ? "padding" : undefined}
        className="flex-1"
      >
        <View className="pt-4">
          {tenant ? (
            <Brand name={tenant.name} primary={tenant.branding.primary} />
          ) : null}
        </View>
        <View className="flex-1 justify-center gap-4">
          <Title>{t("signIn.title")}</Title>
          <Body>{t("signIn.body")}</Body>
          {notice ? <Notice tone="warning">{notice}</Notice> : null}
          {error ? <Notice tone="danger">{error}</Notice> : null}
          {!challenge ? (
            <Field
              label={t("signIn.identifier")}
              value={identifier}
              onChangeText={setIdentifier}
              keyboardType="phone-pad"
              autoComplete="tel"
              textContentType="telephoneNumber"
              placeholder="082 123 4567"
            />
          ) : (
            <>
              <Notice tone="info">{t("signIn.sent")}</Notice>
              <Field
                label={t("signIn.pin")}
                big
                value={pin}
                onChangeText={(v) => {
                  const digits = v.replace(/\D/g, "").slice(0, 6);
                  setPin(digits);
                  if (digits.length === 6) void verify(digits);
                }}
                keyboardType="number-pad"
                autoComplete="sms-otp"
                textContentType="oneTimeCode"
                maxLength={6}
                autoFocus
              />
            </>
          )}
        </View>
        <View className="gap-3">
          {!challenge ? (
            <Button
              title={t("signIn.send")}
              loading={busy}
              disabled={identifier.trim().length < 6}
              onPress={() => void send()}
            />
          ) : (
            <>
              <Button
                title={t("signIn.verify")}
                loading={busy}
                disabled={pin.length !== 6}
                onPress={() => void verify()}
              />
              <Button
                title={t("signIn.paste")}
                variant="ghost"
                disabled={busy}
                onPress={() => void paste()}
              />
              <Button
                title={
                  wait > 0
                    ? t("signIn.resendIn", { s: wait })
                    : t("signIn.resend")
                }
                variant="ghost"
                disabled={wait > 0 || busy}
                onPress={() => void send()}
              />
            </>
          )}
          <Pressable
            onPress={() => void changeTenant()}
            className="items-center py-2"
            accessibilityRole="button"
          >
            <Text className="font-medium text-small text-muted dark:text-night-muted">
              {t("signIn.switch")}
            </Text>
          </Pressable>
        </View>
      </KeyboardAvoidingView>
    </Screen>
  );
}
