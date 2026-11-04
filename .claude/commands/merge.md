Merge the specified branch into the current branch, then push.

Branch to merge: $ARGUMENTS

Instructions:
1. If "$ARGUMENTS" is empty or blank, ask the user which branch they want to merge.
2. Run `git status` to check for uncommitted changes. If there are any, ask the user to commit or stash them first before proceeding.
3. Run `git pull` to update the current branch from remote.
4. Run `git merge $ARGUMENTS` to merge the specified branch into the current branch.
5. If there are merge conflicts:
   - Run `git diff --name-only --diff-filter=U` to list conflicted files.
   - Show the user the list of conflicted files and ask them to resolve the conflicts.
   - Wait for the user to confirm conflicts are resolved.
   - After confirmation, run `git add` on the resolved files and `git commit` to complete the merge.
6. If no conflicts, the merge completes automatically.
7. Run `git push` to push the merged changes to remote.
8. Confirm success with a short summary of what was merged and pushed.
