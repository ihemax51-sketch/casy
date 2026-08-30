
set(SOURCE_FILES
        src/DllMain.cpp
        src/Util.cpp src/Util.h
        src/GameServerConsole.cpp src/GameServerConsole.h
        src/GameServerCrashHandler.cpp src/GameServerCrashHandler.h
        src/StaticPatches.cpp src/StaticPatches.h
        "${CMAKE_SOURCE_DIR}/../native/KMTGuard.Licensing.Native/LicenseVerifier.cpp"
        "${CMAKE_SOURCE_DIR}/../native/KMTGuard.Licensing.Native/PackageBindingVerifier.cpp"
        "${CMAKE_SOURCE_DIR}/../native/KMTGuard.Licensing.Native/ProtectionRuntime.cpp")

add_library(OutPutGS SHARED ${SOURCE_FILES} ${VERSION_INFO})
option(KMT_DEVELOPMENT_BUILD "Build internal test binaries without customer activation" OFF)
if (KMT_DEVELOPMENT_BUILD)
    target_compile_definitions(OutPutGS PRIVATE KMT_DEVELOPMENT_BUILD=1 KMT_REQUIRE_PACKAGE_BINDING=1)
else ()
    target_compile_definitions(OutPutGS PRIVATE KMT_DEVELOPMENT_BUILD=0 KMT_REQUIRE_PACKAGE_BINDING=1)
endif ()
target_include_directories(OutPutGS PRIVATE
        "${CMAKE_SOURCE_DIR}/../native"
        "${CMAKE_SOURCE_DIR}/../native/KMTGuard.Licensing.Native")
target_link_libraries(OutPutGS GameServer memory winmm advapi32)
if (MSVC)
    target_compile_options(OutPutGS PRIVATE /O2 /Ob2 /Oi /Gy /GS)
    set_property(TARGET OutPutGS APPEND_STRING PROPERTY LINK_FLAGS
            " /OPT:REF /OPT:ICF /INCREMENTAL:NO /DYNAMICBASE /NXCOMPAT")
endif ()

set(KMT_GAMESERVER_OUTPUT_DIRECTORY "C:/Gs" CACHE PATH
        "Directory for the KMTGuard GameServer add-on")
set_target_properties(OutPutGS
        PROPERTIES
        ARCHIVE_OUTPUT_DIRECTORY "${KMT_GAMESERVER_OUTPUT_DIRECTORY}/"
        LIBRARY_OUTPUT_DIRECTORY "${KMT_GAMESERVER_OUTPUT_DIRECTORY}/"
        RUNTIME_OUTPUT_DIRECTORY "${KMT_GAMESERVER_OUTPUT_DIRECTORY}/"
        ARCHIVE_OUTPUT_DIRECTORY_RELEASE "${KMT_GAMESERVER_OUTPUT_DIRECTORY}/"
        LIBRARY_OUTPUT_DIRECTORY_RELEASE "${KMT_GAMESERVER_OUTPUT_DIRECTORY}/"
        RUNTIME_OUTPUT_DIRECTORY_RELEASE "${KMT_GAMESERVER_OUTPUT_DIRECTORY}/"
        OUTPUT_NAME "KMTGuard_GameServer"
        )
