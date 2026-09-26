import { readFileSync } from "node:fs";
type Credentials = { email: string; password: string };
export function credentialsFor(scenario: string): Credentials {
  const file = process.env.ERP_E2E_CREDENTIALS;
  if (!file)
    throw new Error("An isolated browser-test credentials file is required.");
  const credentials = JSON.parse(readFileSync(file, "utf8")) as Credentials & {
    scenarios?: Record<string, Credentials>;
  };
  const result = credentials.scenarios?.[scenario];
  if (!result)
    throw new Error(
      "Re-seed the browser-test store to create the scenario account: " +
        scenario,
    );
  return result;
}
