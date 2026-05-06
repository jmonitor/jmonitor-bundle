---
description: Tag a release, update CHANGELOG.md, push tag, and create GitHub release
argument-hint: <version>
---

Release version $ARGUMENTS of the project. Follow these steps exactly:

## 1. Validate the version

- The version must follow semver (e.g. `1.2.3`). Reject anything that doesn't match.
- Run `git tag --list` to see existing tags. Check that the requested version is strictly greater than the latest existing tag. If not, stop and warn the user.
- Run `gh release list --limit 5` to verify consistency between tags and GitHub releases.

## 2. Check CHANGELOG.md

- Read `CHANGELOG.md` and confirm there is an `## [Unreleased]` section with actual content beneath it. If the section is empty or missing, stop and ask the user to fill it in first.

## 3. Update CHANGELOG.md

- Replace `## [Unreleased]` with `## [$ARGUMENTS] - DATE` where DATE is today's date in `YYYY-MM-DD` format (use the `currentDate` context provided in the system prompt).
- Do NOT add a new `## [Unreleased]` section — leave the file as-is after the replacement.
- The format must comply with [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 4. Commit and tag

- Stage only `CHANGELOG.md`: `git add CHANGELOG.md`
- Commit with message: `Release $ARGUMENTS`
- Create an annotated tag: `git tag -a v$ARGUMENTS -m "Release v$ARGUMENTS"`

## 5. Push

- Push the commit: `git push`
- Push the tag: `git push origin v$ARGUMENTS`

## 6. Create GitHub release

- Extract the changelog block for this version: everything between `## [$ARGUMENTS] - DATE` and the next `## [` heading (excluded), stripping the section heading itself.
- Run: `gh release create v$ARGUMENTS --title "v$ARGUMENTS" --notes "<extracted content>"`
- Report the release URL to the user.
