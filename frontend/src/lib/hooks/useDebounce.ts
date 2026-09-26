import { useEffect, useState } from "react";

/** Search waits this long after the last keystroke before querying the server. */
export const SEARCH_DEBOUNCE_MS = 400;

/**
 * Returns `value` once it has stopped changing for `delay` ms, so typing a word
 * sends one request when the user pauses instead of one per keystroke.
 */
export function useDebounce<T>(value: T, delay = SEARCH_DEBOUNCE_MS): T {
  const [settled, setSettled] = useState(value);
  useEffect(() => {
    const timer = setTimeout(() => setSettled(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);
  return settled;
}
