## Release checklist

1. Confirm the scope and choose the next semantic version.
2. Update both the plugin `Version:` header and `SAT_VERSION`.
3. Update release-facing documentation and upgrade notes.
4. Run PHP lint and all repository tests.
5. Confirm the install schema and required migration are current.
6. Build the plugin ZIP from the tested commit.
7. Verify the ZIP contains no development scripts, test fixtures, credentials,
   generated OFX files, or local examples.
8. Install the ZIP and perform a smoke test.
9. Commit the release changes.
10. Tag the same commit as `vMAJOR.MINOR.PATCH`.
11. Push the commit and tag.