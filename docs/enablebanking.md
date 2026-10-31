# Bank sync with EnableBanking

Budget reads bank accounts through [EnableBanking](https://enablebanking.com/), a licensed PSD2 open banking provider covering most European banks. Each self-hosted instance uses its own EnableBanking application. This page explains what to set up on the EnableBanking side, how the app uses the API, and what the sync does.

The same steps are shown in the app, with a live checklist and the exact redirect URL of your instance: **Bank connections → Set up bank sync** (`/{household}/bank-connections/setup`).

Sources: the EnableBanking [API reference](https://enablebanking.com/docs/api/reference/), [Control Panel](https://enablebanking.com/docs/api/control-panel/), [Linked accounts](https://enablebanking.com/docs/api/linked-accounts/), [Quick start](https://enablebanking.com/docs/api/quick-start/) and [terms of service](https://enablebanking.com/terms/). EnableBanking changes its interface from time to time: if a label below does not match, the steps are still the same.

## Restricted mode ("Activate by linking accounts")

- A **production** application is created **Inactive**. The **Activate by linking accounts** button takes you through EnableBanking's authorization page, then your bank's; once an account is linked, the application becomes **active in restricted mode**, without a contract.
- In restricted mode, **only linked accounts** can be read through the API. Anyone can go through the authorization flow, but an account that is not linked returns no data.
- Linking an account **does not** authorize the app: you still connect the bank from Budget (`POST /auth`), "even for the same account".
- Terms of service: restricted use is meant for evaluation or for **personal use by individuals**; the number of linked accounts per application may be limited. A linked account is a payment account attached to the application from the Control Panel, with the strong authentication and consent required by the bank.

Full (unrestricted) activation requires a contract and a review by EnableBanking; a household instance does not need it.

### Accounts of other household members

The documentation does not talk about accounts held by someone else, but the definition of a linked account (strong authentication and consent at the bank, done from the Control Panel) leads to this flow:

1. From **your Control Panel**, on the application, link accounts again and choose the other member's bank.
2. **The other member signs in to their bank themselves** (credentials, confirmation in their banking app). The simplest is to do it together, on the same computer.
3. Then they sign in to Budget with **their own login** (as a member of the household), open **Bank connections**, choose their bank and authorize access once more. It is their connection: only they can renew it.

Using it for a household is covered by "personal use by individuals". Not yet confirmed with every bank: if a bank refuses to be linked from someone else's Control Panel, please open an issue.

## Setting up EnableBanking

1. Sign in on <https://enablebanking.com/sign-in/> with your email address (the first sign-in creates the account) and open the **Control Panel**.
2. Open **API applications** and register a new application:
    - Environment: **Production** (Sandbox only shows test banks);
    - Name: anything, for example `Budget`;
    - Redirect URLs: `APP_URL` followed by `/bank/callback`, for example `https://budget.example.com/bank/callback`. It must match exactly. The redirect goes through the browser (the bank never calls your server), so a local address such as `https://budget.test/bank/callback` works for development; add one URL per address you use;
    - description, data protection email, privacy policy URL and terms of service URL: required in production and shown to whoever authorizes access. For a private instance, your own email address and simple pages are enough;
    - key: either let the **browser generate it** (a `.pem` file named after the application ID is downloaded; it is the private key and never leaves your computer), or **provide your own public key**, generated with:

        ```bash
        openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out enablebanking.pem
        openssl rsa -in enablebanking.pem -pubout -out enablebanking.pub.pem
        ```

        and paste the content of `enablebanking.pub.pem` in the form. Keep `enablebanking.pem` private and out of git.

    - Press **Register**.
3. Copy the **application ID** (a UUID, also the name of the browser-generated `.pem` file).
4. Configure the instance:
    - `ENABLEBANKING_APP_ID=<application ID>` in `.env` (or the container's environment);
    - the private key, in one of three ways: the file at `storage/app/private/enablebanking.pem` (the default, rename the downloaded file), a file elsewhere with `ENABLEBANKING_PRIVATE_KEY_PATH=/path/to/key.pem`, or the key itself, base64-encoded, in `ENABLEBANKING_PRIVATE_KEY` (`base64 < enablebanking.pem | tr -d '\n'`), handy with Docker and PaaS. When `ENABLEBANKING_PRIVATE_KEY` is set, it wins over the file;
    - if the configuration is cached, `php artisan config:clear` (or restart the container).

    The setup page in the app then shows the application ID as set and the key as found and valid. It only shows these checks, never the key.

5. On the application in the Control Panel, click **Activate by linking accounts**, choose your bank, sign in and select the accounts to link. The application becomes active (restricted). Link the other members' accounts as described above.
6. In Budget: **Bank connections → Connect a bank** → choose the bank → authorize → map each bank account to a budget account (or create one, or leave it out).

## What the app calls

Base URL: `https://api.enablebanking.com` (`ENABLEBANKING_BASE_URL`). Every call carries `Authorization: Bearer <JWT>`:

- header `{"typ": "JWT", "alg": "RS256", "kid": "<application ID>"}`;
- payload `{"iss": "enablebanking.com", "aud": "api.enablebanking.com", "iat": …, "exp": …}`, valid for at most 24 hours (the app signs one-hour tokens);
- RS256 signature with the application's private key.

The client is `app/Banking/EnableBankingClient.php`.

| Call                                           | Use                                                                                                                                             |
| ---------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /aspsps?country=FR&psu_type=personal`     | list of banks in a country (cached for a day), with `maximum_consent_validity` in seconds                                                       |
| `POST /auth`                                   | `access.valid_until`, `aspsp {name, country}`, `state`, `redirect_url`, `psu_type` → `url` to send the member to                                |
| back on `redirect_url`                         | `?code=…&state=…`, or `?error=access_denied&error_description=…&state=…`                                                                        |
| `POST /sessions`                               | `{code}` → `session_id`, `accounts[]` (`uid`, `identification_hash`, `account_id.iban`, `details`, `product`, `currency`), `access.valid_until` |
| `GET /accounts/{uid}/transactions?date_from=…` | pages followed through `continuation_key`; status `BOOK`, `PDNG`, `HOLD`, `CNCL`, `RJCT`, `SCHD`, `OTHR`                                        |
| `GET /accounts/{uid}/balances`                 | `balance_type` `CLBD`, `XPCD`, `ITAV`…                                                                                                          |
| `DELETE /sessions/{id}`                        | revokes the consent on disconnect and on renewal                                                                                                |

Notes from the documentation:

- an account's `uid` is only valid for its session: on renewal, the app finds each account again by its `identification_hash` and keeps its mapping and history;
- errors come as `{message, code, error, detail}`; `EXPIRED_SESSION` marks the connection as "Access expired";
- PSD2 limits reads **without the member present** to 4 a day per consent: the scheduled sync runs 4 times a day (6:00, 11:00, 16:00, 21:00, in `APP_TIMEZONE`). "Sync now" sends the `Psu-Ip-Address` and `Psu-User-Agent` headers to tell the bank the member is there;
- the app asks for a consent as long as the bank allows (`maximum_consent_validity`), capped at 180 days. A banner warns the member who connected the bank 7 days before it expires; only they can renew it.

## Scheduler and queue

The scheduled sync needs the Laravel scheduler and a queue worker:

```bash
php artisan schedule:work   # runs bank:sync at 6:00, 11:00, 16:00 and 21:00
php artisan queue:work      # runs the SyncBankConnection jobs
```

In production, use a cron entry (`* * * * * php artisan schedule:run`) or `schedule:work` under a process manager. The Docker image runs both (see [self-hosting.md](self-hosting.md)).

`php artisan bank:sync` queues a sync of every connection whose consent is still valid, on demand. "Sync now" and saving the account mapping sync right away, without the worker.

## Import rules

- Only transactions dated **after** the account's start date are imported. An account created from the mapping screen starts today, with the bank's balance.
- Each sync reads again from the previous sync minus 10 days.
- Deduplication: the bank's identifier (`entry_reference`, else `transaction_id`), else a fingerprint (value date, amount, normalised raw label), else a pending transaction of the same amount within ±5 days.
- An approved transaction whose amount changes goes back to review ("Amount changed"); a pending transaction that disappears is flagged ("Gone from the bank"), never deleted; a manually entered transaction that matches gives "Possible duplicate" with a "Merge" action.
- Labels: default clean-up (French bank prefixes such as `CARTE dd/mm/yy`, `PRLV SEPA`, `VIR SEPA`; a `CB*1234` suffix is kept as the card), then renaming rules, then the payee is found or created, with its last category pre-filled.
- Bank balance used, in order of preference: `XPCD` (expected), `CLBD`, `ITBD`, `ITAV`, `CLAV`, `OPBD`, `OPAV`.

## Troubleshooting

- **"EnableBanking is not configured"**: the application ID or the key is missing; the setup page tells which.
- **"The EnableBanking private key cannot be read"**: the key is not a valid RSA private key (a public key was used, or the base64 value is truncated).
- **The bank sends you back to an error page**: the redirect URL registered in the Control Panel does not match `APP_URL/bank/callback` exactly (check `http` vs `https` and the domain).
- **The connection works but no transactions arrive**: the account is probably not linked to the application in the Control Panel (restricted mode only reads linked accounts).
- **No automatic syncs**: the scheduler or the queue worker is not running.
