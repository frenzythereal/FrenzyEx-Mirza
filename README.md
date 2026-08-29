# FrenzyEx Gateway for Mirza Bot

Adds FrenzyEx as a payment option to a self-hosted [Mirza Bot](https://github.com/mahdiMGF2/mirzabot).

Supported: **0.3**, **0.3.1**, **0.4** and **0.4.1**.

## Install

On the server running your bot, as root:

```bash
curl -o install.sh -L https://raw.githubusercontent.com/frenzythereal/FrenzyEx-Mirza/main/install.sh && bash install.sh
```

Pick option **1**. The installer finds your bot, checks its version, installs
the gateway, and asks for your two keys.

## Getting your keys

Message [@frenzyishere](https://t.me/frenzyishere) with:

- your shop name
- your TRON (TRC20) wallet for settlement
- your callback URL — option 5 prints it, it looks like
  `https://your-domain/payment/frenzyex.php`

You get back an **API key** and an **IPN signing key**. Both are shown once, and
you need both: the API key creates payments, the signing key proves a
confirmation really came from FrenzyEx. With only one of them the button stays
hidden, on purpose.

## Repository layout

The five PHP sources may sit either in `src/` or beside `install.sh` at the
repository root. The installer tries `src/` first and falls back to the root, so
either upload works.

## What it changes

Two files of your bot are edited:

| File | Change |
|---|---|
| `index.php` | one `elseif` branch that creates the payment |
| `keyboard.php` | the payment button |

Both edits sit between `>>> FRENZYEX GATEWAY` markers and both files are backed
up to `frenzyex_backup/<timestamp>/` first. Option 6 removes the markers and
restores the originals byte for byte.

Three files are added: `payment/frenzyex.php`, `frenzyex_lib.php`, and a
settings migration (0.4+ only — 0.3 has no migrations folder, and the installer
writes those settings directly instead). Nothing else is touched — `admin.php`, `function.php` and
the language files are left alone, and settings are written straight into the
bot's own `PaySetting` table.

A patched file is checked with `php -l` before it is written. If the result
would not parse it is discarded and your original is left exactly as it was.

## After a Mirza update

An update replaces `index.php` and `keyboard.php`, which removes the two edits.
Run option **1** again — it is the same operation as a first install, and it
will not duplicate anything. Your keys stay in the database.

## Checking it works

- **3** — POSTs your callback URL. A healthy install answers **401**: an
  unsigned request must be rejected.
- **4** — checks the FrenzyEx API is reachable and your key is valid.
- **5** — shows the version, what is patched, and your settings.

Then buy something small and pay it. The service should be delivered
automatically and the payment should appear in your report channel.

## If your version is not supported

The installer inspects your files before changing them. If the structure it
needs is not there it stops without writing anything and tells you so. Send
your Mirza version to [@frenzyishere](https://t.me/frenzyishere).
