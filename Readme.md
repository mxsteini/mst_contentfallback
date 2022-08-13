# EXT:mst_contentfallback - try to deliver the content as in siteconfiguration defined.

## Problem
At the moment (TYPO3 v11) can not deliver translations on contentlevel.
At the very moment a page is translated, you have to provide a contentelement for each transalation.
The only fallback is the 0-element.

## The fix - How the content fallback works

While these problem belongs to the basics how TYPO3 deals with translations, this approach is a workaround.

See section "Known Problem" for more ...

Asuming we have these translation

with the following content:

| en          | de_DE | de_CH | es_ES | es_MX       |
|-------------|-------|-------|-------|-------------|
| en0         | de0   | ch0   | es0   | mx0         |
| en1         | de1   |       | es1   | mx1(hidden) |
| en2         |       |       |       |             |
| en3(hidden) | de3   | ch3   | es3   | mx3         |


results in

| en  | de_DE | de_CH | es_ES | es_MX |
|-----|-------|-------|-------|-------|
| en0 | de0   | ch0   | es0   | mx0   |
| en1 | de1   | de1   | es1   |       |
| en2 | en2   | en2   | en2   | en2   |
|     |       |       |       |       |

only elements could be displayed which are displayed in language 0

## Installation
```json
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/mxsteini/mst_contentfallback.git"
    }
  ]
```

```bash
composer req mst/mst-contentfallback
```

## Known problemes
* To deliver content, the 0-language must be part of the fallbackchain.
* workspaces are not tested
* the baselanguage-concept itself


