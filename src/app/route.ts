import { UserService } from "@/user.service";

const userService = new UserService();

export async function POST(request: Request) {
  const target = request.headers.get("x-amz-target");
  if (!target) {
    return Response.json({ message: "Invalid request" }, { status: 400 });
  }
  const [service, action] = target.split(".");
  if (service !== "AWSCognitoIdentityProviderService") {
    return Response.json({ message: "Invalid service" }, { status: 400 });
  }

  const options = await request.json();

  switch (action) {
    case "AdminCreateUser":
      const user = await userService.inviteUser(options.Username);
      return Response.json({
        User: {
          Username: options.Username,
          Attributes: [
            {
              Name: "sub",
              Value: user.sub,
            },
          ],
          UserCreateDate: user.created_at,
          Enabled: true,
          UserStatus: "CONFIRMED",
        },
      });
    case "AdminInitiateAuth":
    case "GlobalSignOut":
    case "InitiateAuth":
      const result = await userService.authenticate(options);
      return Response.json(result);
    case "SignUp":
  }

  return Response.json({ message: "Hello, world!" });
}
