import * as Haptics from 'expo-haptics';
import { Pressable, Text, View } from 'react-native';

/** Large, thumb-friendly keypad for the app PIN (works with gloves on site). */
export function PinPad({ value, onChange, length = 6, disabled }: { value: string; onChange: (v: string) => void; length?: number; disabled?: boolean }) {
  const press = (key: string) => {
    if (disabled) return;
    void Haptics.selectionAsync();
    if (key === '⌫') onChange(value.slice(0, -1));
    else if (value.length < length) onChange(value + key);
  };

  return (
    <View className="gap-6">
      <View className="flex-row justify-center gap-3" accessibilityLabel={`${value.length} of ${length} digits entered`}>
        {Array.from({ length }, (_, i) => (
          <View key={i} className={`h-3.5 w-3.5 rounded-full ${i < value.length ? 'bg-primary-700' : 'border border-line bg-surface dark:border-night-line dark:bg-night-surface'}`} />
        ))}
      </View>
      <View className="flex-row flex-wrap justify-between gap-y-3">
        {['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', '⌫'].map((k, i) =>
          k === '' ? (
            <View key={i} className="h-16 w-[30%]" />
          ) : (
            <Pressable
              key={i}
              onPress={() => press(k)}
              accessibilityRole="button"
              accessibilityLabel={k === '⌫' ? 'Delete' : k}
              className="h-16 w-[30%] items-center justify-center rounded-2xl bg-surface active:bg-primary-50 dark:bg-night-surface"
            >
              <Text className="font-semibold text-[24px] text-ink dark:text-night-ink">{k}</Text>
            </Pressable>
          ),
        )}
      </View>
    </View>
  );
}
