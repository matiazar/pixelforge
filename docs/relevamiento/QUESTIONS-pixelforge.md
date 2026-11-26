# Questions: image generation app (Pixelforge)

Español: [PREGUNTAS-pixelforge.md](PREGUNTAS-pixelforge.md)

Date: 2026-11-26

Each question includes what we do if there is no answer. Those marked [key] change the work the most.

## Costs

1. [key] Before an image is generated, its final price is not known yet. What should the user see: an approximate value, a range (minimum and maximum) or only the maximum possible?
   If no answer: a range, with the maximum highlighted.
   Answer: an exact price is not possible: a range.

2. Should the user see only their final price, or also what the provider charges and how much the 25% markup is?
   If no answer: only their final price.
   Answer: only the final price.

## Using the app

3. [key] The provider allows at most 3 images in process at the same time, across all users. If someone asks for a fourth, should it wait in a queue and go out on its own, or should we show a notice to try again later?
   If no answer: a clear notice to try again, without losing what they wrote.
   Answer: a notice that it goes to the queue.

4. [key] Can anyone create an account on the published version, or only people you authorize? With open registration, a stranger could use up the provider's daily limit.
   If no answer: open registration, with a daily limit per user.
   Answer: OK with the assumption.

5. When an image fails, do you want a button to try again with the same data?
   If no answer: yes, the user decides. An image that already failed is not retried on its own.
   Answer: yes, a retry button.

6. What language should the app be in?
   If no answer: English.
   Answer: English.

## Extras (only if included)

7. Report for admins: who should have access, and in which time zone are days counted?
   If no answer: a test account with admin rights, and days in universal time (UTC), like the provider.
   Answer: global admin settings, including the time zone.

8. Balance: to allow a generation, is it enough for the user to have balance for the approximate value, or must it cover the maximum possible?
   If no answer: it must cover the maximum possible. It is held and the difference is returned later.
   Answer: OK.

## Delivery

9. [key] When you ask to deliver the app "with some generations available", do you mean leaving finished examples in the history, leaving room for you to generate more, or both?
   If no answer: both.
   Answer: both.

10. How will the reviewers get in: create their own account, or use a test account prepared in advance?
    If no answer: a test account, with the details sent with the delivery.
    Answer: OK, test account.

11. Is there a delivery deadline?
    Answer: today.
