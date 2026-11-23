/**
 * Draws agreement wording: "# " and "## " headings, numbered clauses, and paragraphs
 * separated by blank lines. The same rules as the PDF; text only, never HTML.
 */
export function AgreementText({ text }: { text: string }) {
  const blocks = text
    .replace(/\r/g, '')
    .trim()
    .split(/\n{2,}/);
  return (
    <div className="grid gap-2.5 text-[15px] leading-relaxed text-ink">
      {blocks.map((raw, i) => {
        const block = raw.trim();
        if (block.startsWith('## '))
          return (
            <h3 key={i} className="mt-3 text-[16px] font-semibold">
              {block.slice(3)}
            </h3>
          );
        if (block.startsWith('# '))
          return (
            <h2 key={i} className="text-[20px] font-semibold tracking-tight">
              {block.slice(2)}
            </h2>
          );
        return (
          <p key={i} className={/^\d+(\.\d+)*\.?\s/.test(block) ? 'pl-2 whitespace-pre-line' : 'whitespace-pre-line'}>
            {block}
          </p>
        );
      })}
    </div>
  );
}
