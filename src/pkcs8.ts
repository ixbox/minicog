import { readFileSync } from "fs";

export const pkcs8 = readFileSync("server.pem", "utf-8");
