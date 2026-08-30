# mapd has moved

It now lives in the launcher repository, at `server/mapd`:

    https://github.com/no-body-in-particular/pt880-launcher

mapd and the watch share a wire format and nothing else does. Adding the
roundabout exit meant changing the encoder here and the parser there and getting
the WRT2 layout to agree by reading the two side by side, which is the kind of
coupling that goes wrong quietly when the halves live in different repositories.

What stays here is `server/map`: the PHP import and tile-building side, which
builds the road stores and the graphs the watch downloads. That is a different
job from answering the watch, and it is not what the watch talks to.
