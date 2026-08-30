#pragma once

class CICPlayer;

namespace KillerAnimationPlayer {
    bool IsValidAnimationId(int animationId);
    bool PlayOnAnimationObject(void* animationObject, int animationId, int blendIn = 0, int duration = 750);
    bool PlayOnPlayer(CICPlayer* player, int animationId);
    bool PlayOnCurrentPlayer(int animationId);
}
