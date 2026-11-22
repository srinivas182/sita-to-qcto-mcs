import * as D from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import type { ReactNode } from 'react';

export function Dialog({
  open,
  onOpenChange,
  title,
  description,
  children,
  footer,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <D.Root open={open} onOpenChange={onOpenChange}>
      <D.Portal>
        <D.Overlay className="fixed inset-0 z-40 bg-primary-950/30 backdrop-blur-[2px] data-[state=open]:animate-in" />
        <D.Content className="card fixed inset-x-3 bottom-3 z-50 max-h-[90vh] overflow-y-auto p-6 shadow-2xl data-[state=open]:animate-in sm:inset-x-auto sm:top-1/2 sm:bottom-auto sm:left-1/2 sm:w-[min(520px,92vw)] sm:-translate-x-1/2 sm:-translate-y-1/2">
          <div className="mb-4 flex items-start justify-between gap-4">
            <div>
              <D.Title className="text-[16px] font-semibold text-ink">{title}</D.Title>
              {description ? <D.Description className="mt-1 text-small text-muted">{description}</D.Description> : null}
            </div>
            <D.Close
              className="grid size-8 place-items-center rounded-md text-muted hover:bg-line-soft"
              aria-label="Close"
            >
              <X className="size-4" />
            </D.Close>
          </div>
          {children}
          {footer ? <div className="mt-6 flex justify-end gap-2">{footer}</div> : null}
        </D.Content>
      </D.Portal>
    </D.Root>
  );
}
