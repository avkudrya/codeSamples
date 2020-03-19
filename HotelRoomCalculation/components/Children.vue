<template>
  <div class="children-ages">
    <child
      v-for="(child, index) in childrenArray"
      :index="index"
      :key="`${child}_${index}`"
      :child="child"
      @updateAge="updateAge($event, index)"
      @deleteItem="deleteItem(index)"
    ></child>
    <button class="btn-add-children" type="button" @click="addChild">+</button>
    <input type="hidden" name="children" :value="childrenString">
  </div>
</template>

<script type="text/babel">
  import Child from './Child.vue';
  import { eventBus } from '../app';

  export default {
    data() {
      return {
        childrenArray: [],
        childrenString: ""
      }
    },
    props: ['children'],
    methods: {
      addChild() {
        this.childrenArray.push("0");
        this.makeString();
      },
      makeString() {
        this.childrenString = this.childrenArray.length > 0 ? this.childrenArray.join(",") : "";
      },
      makeArray(children) {
        var isAny = children.indexOf(',');
        this.childrenArray = isAny != -1 ? children.split(',') : children.length > 0 ? [children] : [];
      },
      updateAge(age, index) {
        this.childrenArray[index] = age;
        this.makeString();
      },
      deleteItem(index) {
        this.childrenArray.splice(index, 1);
        this.makeString();
      }
    },
    components: {
      child: Child
    },
    created() {
      this.makeArray(this.children);
      this.makeString();
    },
    mounted(){
      eventBus.$on('updateChildren', (children) => {
        this.makeArray(children)
      });
    }
  }
</script>

<style scoped>
  .children-ages {
    display: flex;
    flex-direction: row;
    align-items: flex-start;
    justify-content: flex-start;
  }

  .btn-add-children {
    background: rgba(210, 221, 238, 0.4);
    border: none;
    font-size: 19px;
    line-height: 37px;
    border-radius: 3px;
    cursor: pointer;
  }
</style>
