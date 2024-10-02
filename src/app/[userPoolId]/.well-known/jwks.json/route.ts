import { importPKCS8, exportJWK } from "jose";
import { createHash } from "node:crypto";
import { pkcs8 } from "@/pkcs8";
export async function GET() {
  const privateKey = await importPKCS8(pkcs8, "RS256");
  const publicKeyJwk = await exportJWK(privateKey);

  publicKeyJwk.use = "sig";
  publicKeyJwk.kid = createHash("md5").update(pkcs8).digest("hex");

  return Response.json({ keys: [publicKeyJwk] });
}
