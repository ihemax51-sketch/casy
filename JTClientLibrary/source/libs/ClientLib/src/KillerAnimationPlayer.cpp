#include "KillerAnimationPlayer.h"

#include "CObjAnimation.h"
#include "CObjCharacter.h"
#include "ICPlayer.h"
#include <Windows.h>

namespace KillerAnimationPlayer {

bool IsValidAnimationId(int animationId)
{
    return animationId > 0 && animationId <= 500;
}

bool PlayOnAnimationObject(void* animationObject, int animationId, int blendIn, int duration)
{
    if (!animationObject || !IsValidAnimationId(animationId)) {
        return false;
    }

    bool attempted = false;

    __try {
        reinterpret_cast<CCObjAnimation*>(animationObject)->Func_3(animationId, blendIn, duration, 0, 1.0f, 0.1f);
        attempted = true;
    } __except(EXCEPTION_EXECUTE_HANDLER) {
    }

    __try {
        reinterpret_cast<CCObjCharacter*>(animationObject)->FUN_00a5faf0(animationId, blendIn, duration, 0, 1065353216, 1065353216);
        attempted = true;
    } __except(EXCEPTION_EXECUTE_HANDLER) {
    }

    return attempted;
}

bool PlayOnPlayer(CICPlayer* player, int animationId)
{
    if (!player || !player->m_pCCObjAnimation) {
        return false;
    }

    return PlayOnAnimationObject(player->m_pCCObjAnimation, animationId, 0, 750);
}

bool PlayOnCurrentPlayer(int animationId)
{
    return PlayOnPlayer(g_pMyPlayerObj, animationId);
}

}
