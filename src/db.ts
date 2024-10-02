import { JSONFileSyncPreset } from "lowdb/node";
import { User } from "./types";

export const db = JSONFileSyncPreset<{ users: User[] }>("db.json", {
  users: [],
});
