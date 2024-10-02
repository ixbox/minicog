import { db } from "./db";
import { v4 as uuidv4 } from "uuid";
import { AuthenticateRequest, AuthenticateResponse, User } from "./types";
import { pkcs8 } from "@/pkcs8";
import { importPKCS8, SignJWT } from "jose";

export class UserService {
  async authenticate(props: AuthenticateRequest) {
    if (props.AuthFlow !== "USER_PASSWORD_AUTH") {
      throw new Error("Invalid authentication flow");
    }

    const user = db.data.users.find(
      (user) =>
        user.username === props.AuthParameters.USERNAME &&
        user.password === props.AuthParameters.PASSWORD
    );

    if (!user) {
      throw new Error("Invalid username or password");
    }
    console.log("User found:", user);

    const privateKey = await importPKCS8(pkcs8, "RS256");
    const accessToken = new SignJWT({ sub: user.sub });
    const idToken = new SignJWT(user);

    return {
      AuthenticationResult: {
        AccessToken: await accessToken.setExpirationTime("2h").sign(privateKey),
        RefreshToken: "",
        IdToken: await idToken.setExpirationTime("2h").sign(privateKey),
      },
    } satisfies AuthenticateResponse;
  }

  async inviteUser(props: {
    username: string;
    password?: string;
    attributes?: Record<string, string>;
  }) {
    const user = {
      ...props.attributes,
      sub: uuidv4(),
      username: props.username,
      password: props.password ?? "password",
      created_at: Date.now() / 1000,
    } satisfies User;

    db.update(({ users }) => users.push(user));

    return user;
  }
}
