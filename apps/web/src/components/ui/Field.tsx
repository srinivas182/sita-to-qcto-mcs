import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes } from 'react';
import { cx } from './cx';

const control =
  'h-10 w-full rounded-lg border border-line bg-surface px-3 text-body text-ink placeholder:text-faint transition-[border,box-shadow] focus:border-primary-500 focus:outline-none focus:ring-4 focus:ring-primary-100 aria-[invalid=true]:border-danger aria-[invalid=true]:ring-danger-bg';

export interface FieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string;
  hint?: ReactNode;
  error?: string | null;
  trailing?: ReactNode;
}

export const Field = forwardRef<HTMLInputElement, FieldProps>(function Field(
  { label, hint, error, trailing, className, id, ...rest },
  ref,
) {
  const auto = useId();
  const inputId = id ?? auto;
  const describedBy = error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined;

  return (
    <div className={cx('grid gap-1.5', className)}>
      <div className="flex items-center justify-between">
        <label htmlFor={inputId} className="text-small font-medium text-ink">
          {label}
        </label>
        {trailing}
      </div>
      <input
        ref={ref}
        id={inputId}
        className={control}
        aria-invalid={!!error || undefined}
        aria-describedby={describedBy}
        {...rest}
      />
      {error ? (
        <p id={`${inputId}-error`} className="text-small text-danger" role="alert">
          {error}
        </p>
      ) : hint ? (
        <p id={`${inputId}-hint`} className="text-small text-muted">
          {hint}
        </p>
      ) : null}
    </div>
  );
});

export interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label: string;
}

export function Select({ label, className, id, children, ...rest }: SelectProps) {
  const auto = useId();
  return (
    <div className={cx('grid gap-1.5', className)}>
      <label htmlFor={id ?? auto} className="text-small font-medium text-ink">
        {label}
      </label>
      <select id={id ?? auto} className={cx(control, 'appearance-none pr-8')} {...rest}>
        {children}
      </select>
    </div>
  );
}
