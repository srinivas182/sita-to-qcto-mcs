import { Text, View } from 'react-native';

/** Agreement wording: "# " and "## " headings, numbered clauses, paragraphs. Text only. */
export function AgreementText({ text }: { text: string }) {
  const blocks = text.replace(/\r/g, '').trim().split(/\n{2,}/);
  return (
    <View className="gap-2.5">
      {blocks.map((raw, i) => {
        const b = raw.trim();
        if (b.startsWith('## ')) return <Text key={i} accessibilityRole="header" className="mt-2 font-semibold text-section text-ink dark:text-night-ink">{b.slice(3)}</Text>;
        if (b.startsWith('# ')) return <Text key={i} accessibilityRole="header" className="font-semibold text-title text-ink dark:text-night-ink">{b.slice(2)}</Text>;
        return <Text key={i} className={`font-sans text-body leading-6 text-ink dark:text-night-ink ${/^\d+(\.\d+)*\.?\s/.test(b) ? 'pl-2' : ''}`}>{b}</Text>;
      })}
    </View>
  );
}
