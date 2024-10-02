export type User = {
  sub: string;
  username: string;
  password: string;
  created_at: number;
};

export type AuthenticateRequest = {
  AuthFlow: "USER_PASSWORD_AUTH";
  AuthParameters: {
    USERNAME: string;
    PASSWORD: string;
  };
  ClientId: string;
};

type ChallengeName =
  | "SMS_MFA"
  | "EMAIL_OTP"
  | "PASSWORD_VERIFIER"
  | "CUSTOM_CHALLENGE"
  | "DEVICE_SRP_AUTH"
  | "DEVICE_PASSWORD_VERIFIER"
  | "NEW_PASSWORD_REQUIRED";

type NewDeviceMetadata = {
  DeviceKey: string;
  DeviceGroupKey: string;
};
type AuthenticationResult = {
  AccessToken: string;
  ExpiresIn?: number;
  TokenType?: string;
  RefreshToken: string;
  IdToken: string;
  NewDeviceMetadata?: NewDeviceMetadata;
};
export type AuthenticateResponse =
  | {
      ChallengeName: ChallengeName;
      Session?: string;
      ChallengeParameters: Record<string, string>;
    }
  | {
      AuthenticationResult: AuthenticationResult;
    };
