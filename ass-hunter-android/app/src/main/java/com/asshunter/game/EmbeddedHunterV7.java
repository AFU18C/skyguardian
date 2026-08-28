package com.asshunter.game;

public final class EmbeddedHunterV7 {
    private EmbeddedHunterV7() {}
    public static String data() {
        return HunterData1.data() + HunterData2.data() + HunterData3.data() + HunterData4.data();
    }
}
