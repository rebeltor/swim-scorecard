# Swim Scorecard

<img width="620" height="570" alt="image" src="https://github.com/user-attachments/assets/905fc3af-9069-45e3-af53-ddf6feab3c9e" />

## Using the app

- **Main page:** lists every scorecard, newest first. Anyone can open one to follow the score.
  It opens view-only unless you're a signed-in organizer or that phone has the scorer link.

- **Start (organizers):** tap **Sign in with Google**, type a name for the scorecard, like
  "JD vs Bville", and tap **Start scoring**.

- **Enter places:** open an event and tap the team (**JD** or **Opp** unless you've renamed them),
  **Tie** or **DQ** for each place. Tap a selected button again to clear it. **Next event** moves
  down the meet.

- **Scorers per team:** only 3 swimmers and 2 relays per team can score in an event. If a team's
  4th swimmer (or 3rd relay) is entered, that button turns grey and crossed out, the place gets no
  points, and everyone behind moves up a place. If it happened in the scoring places, a message
  explains it so the scorer can fix a wrong tap. Change the limits under the gear icon > Points.

- **6 or 8 lanes:** events start with 6 places (4 for relays). When a DQ or a team's extra swimmer
  pushes a swimmer into the points, another place appears automatically, so 8- and 10-lane meets
  work with no setting. **Add a place** adds one by hand.

- **Ties:** tap **Tie** on each tied place. For a tie for 4th, tap Tie in 4th and 5th. A two-way
  tie counts as one swimmer from each team, and they split the points. If both tied swimmers were
  on the same team, change it in the box that appears under the tie. For three or more tied
  swimmers, pick how many were from your team.

- **DQ:** tap **DQ** in the place where the disqualified swimmer finished. Everyone behind moves
  up a place. For example, if 5th is a DQ, whoever finished 6th gets 5th-place points. If that
  leaves a scoring place open, the app asks who finished next.

- **Not enough swimmers:** leave the extra places empty. Places nobody fills aren't awarded.

- **Share:** tap the share icon at the top. Organizers and scorers see two links:

  - **Scorer link**, with a QR code: send it to the people entering places. Anyone with it can
    enter places without signing in, and their phone remembers it.
  - **View-only link**: for parents and fans. It shows the live score but nothing can be changed.

  Changes show up on everyone's phone within a few seconds.

- **Teams:** tap the gear icon > **Teams** to rename the teams (up to 10 characters, since the
  names go on the buttons) and pick their colors. New scorecards use JD in red and Opp in orange,
  set in `config.php`.

- **Events and points:** the gear icon also has **Events** (rename, add, remove or reorder events,
  turn relay scoring on or off) and **Points** (points per place and scorers per team). The default
  is 6-4-3-2-1 for individual events and diving, and 8-4-2 for relays.

- **Lock:** the organizer who created a scorecard can lock it from the gear icon when the meet is
  over. Nobody can change anything while it's locked, including scorers with the link. The same
  organizer unlocks it from the gear icon or the **Unlock** button on the scorecard. Locked
  scorecards are marked on the main page.

- **Scorecards you've opened** are listed on that phone's start screen.
