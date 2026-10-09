import { useEffect, useRef, useState } from 'react';
import { useToast } from '../components/Toast/ToastProvider';
import { subscribeChanges, subscribeStamp, lastSuccessfulCheckAt } from '../services/changePoller';

// Step 4.11: a page that should stay current while it is open (Tours, Dashboard, Today).
// onChange(events) runs when the server's change token moved - the page refetches silently.
// New bookings / cancellations are toasted by the shared poller (once, app-wide).
export function useLiveRefresh(onChange) {
  const toast = useToast();
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;
  const toastRef = useRef(toast);
  toastRef.current = toast;

  useEffect(() => subscribeChanges({
    onChange: (events, reason) => onChangeRef.current?.(events, reason),
    notify: (text, kind) => {
      const t = toastRef.current;
      if (kind === 'cancel') t.info(text); else t.success(text);
    },
  }), []);
}

// Time of the last successful change check (null until the first one answers).
export function useLastCheckAt() {
  const [at, setAt] = useState(() => lastSuccessfulCheckAt());
  useEffect(() => subscribeStamp(setAt), []);
  return at;
}
